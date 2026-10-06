<?php

namespace App\Services\Storage;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bounded in-process retry-with-backoff around a single object write
 * (Requirement 8.5).
 *
 * ## Why this exists when the queue already retries
 *
 * Req 8.5 says a failed upload is retried with backoff and the step is not
 * marked complete until storage succeeds, and it names the queued-job retry as
 * the mechanism. That half was already true before this class:
 * {@see TailoredDocumentStorage} raises rather than returns on a failed write,
 * {@see \App\Jobs\TailorResume} and {@see \App\Jobs\TailorCoverLetter} upload
 * *before* promoting the row, and both carry a `$backoff`, so a failed upload
 * releases the job and leaves the document `pending`.
 *
 * What that arrangement gets wrong is the price. A queue retry re-runs the
 * entire job: the tailoring model call, the fabrication inspection, the LaTeX
 * compile — minutes of work and real money — because one PUT got a 503. The
 * proportionate response to a transient write failure is to try the write
 * again, which is what this class does. It sits *below* the queue retry and
 * does not replace it:
 *
 * - this layer absorbs the failures that clear in under a second (a throttle,
 *   a partition re-election, a socket reset);
 * - the queue layer still absorbs everything longer — a bucket that is down for
 *   a minute, a credential rotation in progress, a worker that died mid-job —
 *   because an exhausted retry here still throws, and the job still releases
 *   with its own minutes-long backoff and still leaves the row `pending`.
 *
 * Removing the queue layer in favour of a longer loop here would be strictly
 * worse: it would occupy a worker sleeping, and it would lose the `failed_jobs`
 * record that makes a permanent outage visible to an operator.
 *
 * ## The contract: the caller's failure semantics are preserved exactly
 *
 * This class deliberately does not invent an exception type or a message. On a
 * failure it has run out of attempts for, it re-raises the driver's own
 * throwable, or returns the driver's own `false`. Callers therefore keep the
 * error handling they already had — {@see TailoredDocumentStorage::put()} still
 * turns both into its own `RuntimeException`, and `ResumeController` still turns
 * both into a `failed` row and a 500 — and wrapping a call in this class changes
 * *when* it fails, never *how*.
 *
 * ## Sleeping
 *
 * `usleep` by default, overridable through the constructor so tests can assert
 * that backoff was applied without actually waiting. The suite also sets
 * `filesystems.upload_retry.backoff_ms` to 0, so a test that forgets to inject
 * a sleeper still does not slow anything down.
 */
class StorageWriteRetrier
{
    /**
     * @var callable(int): void receives the delay in milliseconds
     */
    private $sleeper;

    /**
     * @param  (callable(int): void)|null  $sleeper
     */
    public function __construct(?callable $sleeper = null)
    {
        $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    /**
     * Run `$write` until it succeeds, until it fails in a way retrying cannot
     * fix, or until the attempts are used up.
     *
     * `$write` must be idempotent and repeatable — every call site is a PUT of
     * the same bytes to the same key, which is idempotent on S3 by definition,
     * and re-reading an upload temp file or an in-memory string is repeatable.
     * Do not pass a closure that consumes a stream.
     *
     * `$disk` and `$key` are for logging only; the write is entirely the
     * closure's business, so this class never has to know which of `put()`,
     * `putFileAs()` or anything else is being used.
     *
     * @param  callable(int): mixed  $write  receives the 1-based attempt number
     * @param  int|null  $attempts  overrides the configured cap (see the
     *                              `interactive_attempts` config note)
     * @return mixed the closure's successful return value, or its final `false`
     *
     * @throws Throwable the failure of the final attempt, unchanged
     */
    public function write(string $disk, string $key, callable $write, ?int $attempts = null, ?int $backoffMs = null): mixed
    {
        $attempts = $this->attempts($attempts);
        $backoffMs = $this->backoffMs($backoffMs);

        for ($attempt = 1; ; $attempt++) {
            $last = $attempt >= $attempts;

            try {
                $result = $write($attempt);
            } catch (Throwable $e) {
                if ($last || ! $this->isRetryable($e)) {
                    throw $e;
                }

                $this->logRetry($disk, $key, $attempt, $attempts, $e->getMessage());
                $this->backOff($attempt, $backoffMs);

                continue;
            }

            if ($result === false) {
                if ($last) {
                    return false;
                }

                // A `false` return carries no information at all: the disk is
                // configured with `'throw' => false`, so a 403, a 500 and a
                // full local filesystem are the same value. It is retried
                // rather than given up on because the retryable causes are the
                // more common ones and the cost of a wasted attempt is one
                // round trip; the honest statement is that this branch cannot
                // tell a hopeless write from a transient one.
                $this->logRetry($disk, $key, $attempt, $attempts, 'the disk reported a failed write without a reason');
                $this->backOff($attempt, $backoffMs);

                continue;
            }

            if ($attempt > 1) {
                Log::info('storage.write_recovered', [
                    'disk' => $disk,
                    'key' => $key,
                    'attempt' => $attempt,
                ]);
            }

            return $result;
        }
    }

    /**
     * Is retrying this failure worth the delay it costs?
     *
     * The honest answer for most of what a Flysystem/S3 stack throws is "we
     * cannot tell", so the rule is: retry unless something in the chain
     * positively identifies a failure that a retry cannot fix. Retrying a
     * hopeless write wastes one round trip; refusing to retry a transient one
     * escalates a blip into a queue retry that re-pays for a whole tailoring
     * run, so the asymmetry points at retrying by default.
     *
     * What *is* identifiable comes off the AWS exception, which Flysystem
     * chains under `UnableToWriteFile`:
     *
     * - an HTTP status (`getStatusCode()`): a 4xx is the caller being wrong —
     *   bad credentials (403), no such bucket (404), a malformed request — and
     *   will be exactly as wrong in 200ms. The two exceptions are 408 (request
     *   timeout) and 429 (too many requests), which are explicitly "come back
     *   later". 5xx is the service's problem and is retryable.
     * - an AWS error code (`getAwsErrorCode()`), because S3 famously returns
     *   some retryable conditions with a status that reads permanent, and some
     *   permanent ones without a usable status at all.
     *
     * Both are read through `method_exists()` rather than a type check on
     * `Aws\Exception\AwsException`: this class must keep working with a mocked
     * disk in tests and with a non-S3 driver in local development, neither of
     * which throws AWS types, and the SDK is an indirect dependency of the
     * flysystem adapter rather than something this class should couple to.
     *
     * Not detectable here, and worth being explicit about: a local filesystem
     * that is out of space, a permissions problem on the `public` fallback disk,
     * and a Flysystem `UnableToWriteFile` with no chained cause all look
     * retryable and will be retried pointlessly. They fail in milliseconds, so
     * the waste is the backoff and nothing more.
     */
    private function isRetryable(Throwable $error): bool
    {
        for ($e = $error; $e !== null; $e = $e->getPrevious()) {
            $awsCode = method_exists($e, 'getAwsErrorCode') ? $e->getAwsErrorCode() : null;

            if (is_string($awsCode) && $awsCode !== '') {
                if (in_array($awsCode, self::NON_RETRYABLE_AWS_CODES, true)) {
                    return false;
                }

                if (in_array($awsCode, self::RETRYABLE_AWS_CODES, true)) {
                    return true;
                }
            }

            $status = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : null;

            if (is_int($status) && $status >= 400) {
                if ($status >= 500) {
                    return true;
                }

                return in_array($status, [408, 429], true);
            }
        }

        return true;
    }

    /**
     * Configuration, not credentials: an operator who gets these wrong should
     * get a working system, so a nonsensical value is clamped rather than
     * honoured. `attempts` below 1 would skip the write altogether — the one
     * outcome worse than a failed write, since nothing would raise.
     */
    private function attempts(?int $override): int
    {
        $attempts = $override ?? (int) config('filesystems.upload_retry.attempts', 3);

        return max(1, min(10, $attempts));
    }

    private function backoffMs(?int $override): int
    {
        $backoff = $override ?? (int) config('filesystems.upload_retry.backoff_ms', 200);

        return max(0, min(5_000, $backoff));
    }

    /**
     * Doubling backoff, capped, and skipped entirely when it is zero so a test
     * run never pays for it.
     */
    private function backOff(int $attempt, int $backoffMs): void
    {
        if ($backoffMs <= 0) {
            return;
        }

        ($this->sleeper)(min(5_000, $backoffMs * (2 ** ($attempt - 1))));
    }

    /**
     * One shape, so `storage.write_retried` followed by no
     * `storage.write_recovered` for the same key is a grep-able signature of a
     * write that fell through to the queue.
     */
    private function logRetry(string $disk, string $key, int $attempt, int $attempts, string $reason): void
    {
        Log::warning('storage.write_retried', [
            'disk' => $disk,
            'key' => $key,
            'attempt' => $attempt,
            'of' => $attempts,
            'reason' => $reason,
        ]);
    }

    /**
     * Credentials, bucket and request shape — wrong now, wrong in 200ms.
     */
    private const NON_RETRYABLE_AWS_CODES = [
        'AccessDenied',
        'AccountProblem',
        'AllAccessDisabled',
        'EntityTooLarge',
        'InvalidAccessKeyId',
        'InvalidBucketName',
        'InvalidRequest',
        'KeyTooLongError',
        'NoSuchBucket',
        'SignatureDoesNotMatch',
    ];

    /**
     * Conditions AWS documents as "retry this", including the ones that arrive
     * with a status a naive reading would treat as permanent.
     */
    private const RETRYABLE_AWS_CODES = [
        'InternalError',
        'RequestTimeout',
        'RequestTimeTooSkewed',
        'ServiceUnavailable',
        'SlowDown',
        'ThrottlingException',
        'TooManyRequests',
        'TransientError',
    ];
}
