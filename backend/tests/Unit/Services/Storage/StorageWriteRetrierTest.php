<?php

namespace Tests\Unit\Services\Storage;

use App\Services\Storage\StorageWriteRetrier;
use Exception;
use RuntimeException;
use Tests\TestCase;

/**
 * The in-process retry layer under every S3 write (Requirement 8.5).
 *
 * ## Nothing here touches a disk
 *
 * {@see StorageWriteRetrier} is deliberately given a closure and told only a
 * disk name and a key for logging, so the unit under test is the retry decision
 * and nothing else. The closures below stand in for `put()`/`putFileAs()` and
 * return or throw exactly what a driver would: `false` for a swallowed failure,
 * a throwable for `'throw' => true` or a facade-level explosion. That makes the
 * attempt counts assertable by simply counting calls.
 *
 * Sleeps are captured through the injected sleeper rather than performed, so the
 * backoff schedule is asserted precisely and the test costs nothing. The suite
 * also zeroes `backoff_ms` (see phpunit.xml), which is belt-and-braces for any
 * caller that constructs the retrier without a sleeper.
 */
class StorageWriteRetrierTest extends TestCase
{
    /** @var list<int> milliseconds the retrier asked to sleep, in order */
    private array $slept = [];

    private function retrier(): StorageWriteRetrier
    {
        return new StorageWriteRetrier(function (int $milliseconds): void {
            $this->slept[] = $milliseconds;
        });
    }

    public function test_a_successful_write_runs_once_and_returns_its_value(): void
    {
        $calls = 0;

        $result = $this->retrier()->write('s3', 'key.pdf', function () use (&$calls) {
            $calls++;

            return 'written';
        });

        $this->assertSame('written', $result);
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->slept, 'a first-attempt success must not sleep');
    }

    /**
     * The point of the whole class: a bucket that has a bad second costs a
     * retry, not a re-run of the tailoring job that produced the bytes.
     */
    public function test_a_transient_throwing_failure_that_succeeds_on_retry_surfaces_no_error(): void
    {
        $attempts = [];

        $result = $this->retrier()->write('s3', 'key.pdf', function (int $attempt) use (&$attempts) {
            $attempts[] = $attempt;

            if ($attempt === 1) {
                throw new RuntimeException('connection reset by peer');
            }

            return 'written';
        }, attempts: 3, backoffMs: 100);

        $this->assertSame('written', $result);
        $this->assertSame([1, 2], $attempts);
        $this->assertSame([100], $this->slept);
    }

    /** The same, for the `false` return the `'throw' => false` disks give us. */
    public function test_a_transient_false_return_that_succeeds_on_retry_surfaces_no_error(): void
    {
        $calls = 0;

        $result = $this->retrier()->write('s3', 'key.pdf', function () use (&$calls) {
            $calls++;

            return $calls === 1 ? false : true;
        }, attempts: 3, backoffMs: 100);

        $this->assertTrue($result);
        $this->assertSame(2, $calls);
    }

    public function test_attempts_are_bounded_by_configuration(): void
    {
        config(['filesystems.upload_retry.attempts' => 2]);

        $calls = 0;

        $result = $this->retrier()->write('s3', 'key.pdf', function () use (&$calls) {
            $calls++;

            return false;
        }, backoffMs: 100);

        $this->assertFalse($result, 'the final failure is returned unchanged, not wrapped');
        $this->assertSame(2, $calls);
        $this->assertSame([100], $this->slept, 'no sleep after the last attempt');
    }

    /**
     * `attempts: 1` must disable the loop rather than skip the write — a retrier
     * that wrote nothing and raised nothing would be the one failure mode worse
     * than a failed upload.
     */
    public function test_a_single_configured_attempt_still_performs_the_write(): void
    {
        config(['filesystems.upload_retry.attempts' => 1]);

        $calls = 0;

        $this->retrier()->write('s3', 'key.pdf', function () use (&$calls) {
            $calls++;

            return false;
        });

        $this->assertSame(1, $calls);
        $this->assertSame([], $this->slept);
    }

    /** The exhausted throwable is re-raised as-is, so callers keep their handling. */
    public function test_the_final_throwable_is_re_raised_unchanged(): void
    {
        $thrown = new RuntimeException('bucket unreachable');
        $calls = 0;

        try {
            $this->retrier()->write('s3', 'key.pdf', function () use (&$calls, $thrown) {
                $calls++;

                throw $thrown;
            }, attempts: 3, backoffMs: 50);

            $this->fail('Expected the last failure to surface.');
        } catch (RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }

        $this->assertSame(3, $calls);
        $this->assertSame([50, 100], $this->slept, 'backoff doubles between attempts');
    }

    /**
     * A 403 is bad credentials and a 404 is a missing bucket: both are exactly
     * as wrong after a sleep, and retrying them only delays the real error.
     */
    public function test_a_non_retryable_status_code_is_not_retried(): void
    {
        foreach ([403, 404, 400] as $status) {
            $this->slept = [];
            $calls = 0;

            try {
                $this->retrier()->write('s3', 'key.pdf', function () use (&$calls, $status) {
                    $calls++;

                    throw new FakeAwsFailure('refused', statusCode: $status);
                }, attempts: 3, backoffMs: 50);

                $this->fail('Expected a non-retryable failure to surface immediately.');
            } catch (FakeAwsFailure) {
            }

            $this->assertSame(1, $calls, "HTTP {$status} must not be retried");
            $this->assertSame([], $this->slept);
        }
    }

    /** 408/429 and every 5xx are the service saying "later", so they are retried. */
    public function test_retryable_status_codes_are_retried(): void
    {
        foreach ([408, 429, 500, 503] as $status) {
            $calls = 0;

            $result = $this->retrier()->write('s3', 'key.pdf', function (int $attempt) use (&$calls, $status) {
                $calls++;

                if ($attempt === 1) {
                    throw new FakeAwsFailure('later', statusCode: $status);
                }

                return true;
            }, attempts: 2, backoffMs: 0);

            $this->assertTrue($result);
            $this->assertSame(2, $calls, "HTTP {$status} should have been retried");
        }
    }

    /**
     * Classification looks through the chain, because Flysystem wraps the AWS
     * exception in `UnableToWriteFile` and the status only exists on the cause.
     */
    public function test_a_non_retryable_cause_is_detected_through_the_exception_chain(): void
    {
        $calls = 0;

        try {
            $this->retrier()->write('s3', 'key.pdf', function () use (&$calls) {
                $calls++;

                throw new RuntimeException(
                    'Unable to write file at location.',
                    0,
                    new FakeAwsFailure('access denied', awsErrorCode: 'AccessDenied')
                );
            }, attempts: 3, backoffMs: 50);

            $this->fail('Expected the wrapped 403 to surface immediately.');
        } catch (RuntimeException) {
        }

        $this->assertSame(1, $calls);
    }

    /**
     * `SlowDown` is a throttle that arrives with a 503-ish shape but is worth
     * naming explicitly, since it is the failure this layer exists for.
     */
    public function test_a_throttling_aws_code_is_retried(): void
    {
        $calls = 0;

        $result = $this->retrier()->write('s3', 'key.pdf', function (int $attempt) use (&$calls) {
            $calls++;

            if ($attempt === 1) {
                throw new FakeAwsFailure('slow down', awsErrorCode: 'SlowDown');
            }

            return true;
        }, attempts: 2, backoffMs: 0);

        $this->assertTrue($result);
        $this->assertSame(2, $calls);
    }

    /**
     * An unclassifiable failure is retried. Stated as a test because it is a
     * decision, not an accident: a wasted attempt costs one round trip, while
     * refusing to retry a transient failure escalates it to a queue retry that
     * re-pays for the entire tailoring run.
     */
    public function test_an_unclassifiable_failure_is_retried(): void
    {
        $calls = 0;

        try {
            $this->retrier()->write('s3', 'key.pdf', function () use (&$calls) {
                $calls++;

                throw new Exception('something went wrong');
            }, attempts: 2, backoffMs: 0);

            $this->fail('Expected the failure to surface after the attempts ran out.');
        } catch (Exception) {
        }

        $this->assertSame(2, $calls);
    }

    /** A zero backoff must not sleep at all, which is what keeps the suite fast. */
    public function test_a_zero_backoff_never_sleeps(): void
    {
        $this->retrier()->write('s3', 'key.pdf', fn (int $attempt) => $attempt === 1 ? false : true, attempts: 3, backoffMs: 0);

        $this->assertSame([], $this->slept);
    }

    /** Nonsense configuration is clamped rather than honoured. */
    public function test_configuration_is_clamped_to_sane_bounds(): void
    {
        config(['filesystems.upload_retry.attempts' => 0]);

        $calls = 0;

        $this->retrier()->write('s3', 'key.pdf', function () use (&$calls) {
            $calls++;

            return true;
        });

        $this->assertSame(1, $calls, 'a zero attempt cap must still write once');
    }
}
