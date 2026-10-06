<?php

namespace App\Services\Apply;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The ordered list of {@see ApplyAdapter}s, and the first-match lookup
 * `SubmitApplication` uses to pick one for an `application_url`
 * (Requirement 9.1).
 *
 * ## Order is config, and order is significant
 *
 * The list comes from `config('apply.registry')`, a key => class map, and is
 * walked **top to bottom**: the first adapter whose `supports()` returns true
 * wins and no later adapter is consulted. That makes the config file the single
 * place the precedence between two adapters that could both claim a URL is
 * decided — add a narrow, specific adapter *above* a broad one, never below.
 *
 * The shipped order (Greenhouse, Lever, Workday) has no overlap: each matches
 * its own hosts, and the embedded-board tells each one looks for are its own
 * vendor's query params and path markers. Ordering still matters for what comes
 * next — an adapter that matched "any careers page" would have to go last.
 *
 * ## Gated adapters are a second, separate list (Requirement 9.3)
 *
 * `apply.gated_registry` holds the adapters a URL match is *not* sufficient to
 * reach: today that is `LinkedInEasyApplyAdapter`, which drives the user's own
 * signed-in LinkedIn account and runs only for a user who has explicitly opted
 * in ({@see OptInGatedApplyAdapter}).
 *
 * It is a separate map rather than a flag on the same one because the ordinary
 * list is walked by `supports()` alone, and anything reachable that way is
 * reachable by a domain match. Here the order is reversed: consent is checked
 * *first*, and a gated adapter is not even asked whether it supports the URL
 * unless the user has said yes. {@see resolve()} needs a user id to do that, and
 * a missing one means no consent — never "skip the check".
 *
 * {@see gatedMatchFor()} answers the other half of the question for the caller:
 * "a gated adapter would have claimed this URL, but may not run", which
 * {@see \App\Jobs\SubmitApplication} turns into a `needs_review` note telling
 * the user to apply manually or switch the setting on. Knowing that is what
 * stops a LinkedIn posting from being reported as an unsupported ATS.
 *
 * ## Nothing matching is a normal outcome
 *
 * Most postings are on a company's own careers page with no vendor fingerprint.
 * {@see resolve()} returns null for those and the caller sends the listing to
 * `needs_review` with an "apply manually" note — a guessed form fill on an
 * unknown ATS is worse than no attempt, which is the same argument
 * {@see AbstractApplyAdapter::supports()} makes for not claiming a URL.
 *
 * Adapters are resolved from the container (they take the worker client, S3 and
 * the artefact store), and resolution is lazy per lookup but memoised for the
 * life of the registry, so a request or queue job pays for each adapter once.
 * A class that cannot be resolved or is not an {@see ApplyAdapter} is logged and
 * skipped rather than thrown: one bad config entry should cost that adapter, not
 * every application in the queue.
 */
class ApplyAdapterRegistry
{
    /** @var array<string, ApplyAdapter>|null */
    private ?array $resolved = null;

    /** @var array<string, OptInGatedApplyAdapter>|null */
    private ?array $resolvedGated = null;

    public function __construct(private readonly Container $container) {}

    /**
     * The first adapter that claims `$applicationUrl`, or null when none does.
     *
     * Gated adapters are consulted last and only for a `$userId` that has opted
     * in to them. Passing no user id therefore resolves exactly the ungated
     * list, which is the safe reading of "I don't know whose application this
     * is" (Requirement 9.3).
     */
    public function resolve(string $applicationUrl, ?int $userId = null): ?ApplyAdapter
    {
        $url = trim($applicationUrl);

        if ($url === '') {
            return null;
        }

        if (($adapter = $this->firstSupporting($this->all(), $url)) !== null) {
            return $adapter;
        }

        if ($userId === null) {
            return null;
        }

        // Consent before `supports()`, not after: an adapter the user has not
        // opted in to is never asked whether it would like this URL.
        return $this->firstSupporting($this->permittedGated($userId), $url);
    }

    /**
     * A gated adapter that claims `$applicationUrl` but is not permitted for
     * `$userId` — i.e. "this is a LinkedIn posting and LinkedIn automation is
     * off". Null when no gated adapter claims the URL, or when one does and the
     * user has opted in (in which case {@see resolve()} returns it).
     */
    public function gatedMatchFor(string $applicationUrl, ?int $userId): ?OptInGatedApplyAdapter
    {
        $url = trim($applicationUrl);

        if ($url === '') {
            return null;
        }

        foreach ($this->gated() as $adapter) {
            if ($userId !== null && $this->isPermitted($adapter, $userId)) {
                continue;
            }

            if ($this->firstSupporting([$adapter], $url) !== null) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * @param  array<string, ApplyAdapter>  $adapters
     */
    private function firstSupporting(array $adapters, string $url): ?ApplyAdapter
    {
        foreach ($adapters as $adapter) {
            try {
                if ($adapter->supports($url)) {
                    return $adapter;
                }
            } catch (Throwable $e) {
                // `supports()` is host/path matching and has no business
                // throwing, but a malformed config pattern could make it; that
                // must not stop the remaining adapters from being asked.
                Log::warning('apply.adapter_supports_failed', [
                    'adapter' => $adapter::class,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * The gated adapters this user has consented to.
     *
     * @return array<string, OptInGatedApplyAdapter>
     */
    private function permittedGated(int $userId): array
    {
        return array_filter(
            $this->gated(),
            fn (OptInGatedApplyAdapter $adapter): bool => $this->isPermitted($adapter, $userId)
        );
    }

    /**
     * A consent check that throws nothing: it reads the database, and a failure
     * there has to mean "not permitted" rather than an exception that a caller
     * might catch into some other outcome.
     */
    private function isPermitted(OptInGatedApplyAdapter $adapter, int $userId): bool
    {
        try {
            return $adapter->isPermittedFor($userId);
        } catch (Throwable $e) {
            Log::warning('apply.adapter_opt_in_check_failed', [
                'adapter' => $adapter::class,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Every registered adapter, in registration order, keyed by its config key.
     *
     * @return array<string, ApplyAdapter>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach ($this->configured() as $key => $class) {
            try {
                $adapter = $this->container->make($class);
            } catch (Throwable $e) {
                Log::warning('apply.adapter_unresolvable', [
                    'key' => $key,
                    'class' => $class,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $adapter instanceof ApplyAdapter) {
                Log::warning('apply.adapter_invalid', [
                    'key' => $key,
                    'class' => $class,
                    'reason' => 'the configured class does not implement '.ApplyAdapter::class,
                ]);

                continue;
            }

            $resolved[$key] = $adapter;
        }

        return $this->resolved = $resolved;
    }

    /**
     * Every opt-in-gated adapter, keyed by its config key (Requirement 9.3).
     *
     * Kept out of {@see all()} on purpose: that list is the one walked by URL
     * match, and membership of it is the same thing as being reachable by a URL
     * match. A class listed here that does not implement
     * {@see OptInGatedApplyAdapter} is dropped rather than silently treated as
     * ungated — the whole point of the list is the gate, and an adapter with no
     * gate in it would be worse than absent.
     *
     * @return array<string, OptInGatedApplyAdapter>
     */
    public function gated(): array
    {
        if ($this->resolvedGated !== null) {
            return $this->resolvedGated;
        }

        $resolved = [];

        foreach ($this->configured('apply.gated_registry') as $key => $class) {
            try {
                $adapter = $this->container->make($class);
            } catch (Throwable $e) {
                Log::warning('apply.adapter_unresolvable', [
                    'key' => $key,
                    'class' => $class,
                    'gated' => true,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $adapter instanceof OptInGatedApplyAdapter) {
                Log::warning('apply.gated_adapter_invalid', [
                    'key' => $key,
                    'class' => $class,
                    'reason' => 'a gated adapter must implement '.OptInGatedApplyAdapter::class,
                ]);

                continue;
            }

            $resolved[$key] = $adapter;
        }

        return $this->resolvedGated = $resolved;
    }

    /**
     * The config key an adapter is registered under — the same label adapters
     * put in their metadata and screenshot keys, and the key the per-platform
     * daily cap is looked up by, so gated adapters have to be nameable here
     * too. Falls back to the class short name so an unregistered instance is
     * still nameable in a log.
     */
    public function keyFor(ApplyAdapter $adapter): string
    {
        foreach ($this->all() + $this->gated() as $key => $candidate) {
            if ($candidate === $adapter) {
                return $key;
            }
        }

        $short = class_basename($adapter);

        return strtolower(preg_replace('/ApplyAdapter$/', '', $short) ?: $short);
    }

    /**
     * @return array<string, class-string>
     */
    private function configured(string $configKey = 'apply.registry'): array
    {
        $registry = config($configKey, []);

        if (! is_array($registry)) {
            return [];
        }

        $out = [];

        foreach ($registry as $key => $class) {
            if (! is_string($class) || trim($class) === '') {
                continue;
            }

            $out[is_string($key) ? $key : (string) $key] = trim($class);
        }

        return $out;
    }
}
