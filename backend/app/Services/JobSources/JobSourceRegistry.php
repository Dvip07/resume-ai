<?php

namespace App\Services\JobSources;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * The seam that makes Requirement 3.2 true: the orchestrator (task 8.7) asks
 * this registry which sources to fan out to and never names a provider class.
 * Adding a source (tasks 8.2-8.5) means writing the class and adding one entry
 * to `config/job_sources.php` — no change here, and none downstream.
 *
 * Providers are resolved from the container, so their own dependencies (an
 * HTTP client, ModelRouterService for the LLM search source) are injected
 * normally and tests can bind fakes for a key without editing config.
 *
 * Config shape (see `config/job_sources.php`):
 *
 *   'providers' => [
 *       'adzuna' => ['class' => AdzunaJobSourceProvider::class, 'enabled' => true],
 *   ]
 *
 * The array key is authoritative: it must match the provider's own `key()`, and
 * resolution fails loudly if it doesn't. That mismatch would otherwise write
 * rows under one `source_key` while rate-limiting under another.
 */
class JobSourceRegistry
{
    public function __construct(private readonly Container $container) {}

    /**
     * Every configured key, enabled or not, in config order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->config());
    }

    /**
     * Keys whose `enabled` flag is truthy. Entries default to enabled: a
     * provider you bothered to register is on unless explicitly turned off.
     *
     * @return list<string>
     */
    public function enabledKeys(): array
    {
        return array_values(array_keys(array_filter(
            $this->config(),
            static fn (array $entry) => (bool) ($entry['enabled'] ?? true)
        )));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->config());
    }

    public function isEnabled(string $key): bool
    {
        return in_array($key, $this->enabledKeys(), true);
    }

    /**
     * Resolve one provider by key, regardless of its enabled flag — callers
     * that want only live sources should use {@see enabled()}.
     *
     * @throws InvalidArgumentException when the key isn't registered, the
     *                                 configured class isn't a
     *                                 JobSourceProvider, or its `key()`
     *                                 disagrees with its config key
     */
    public function get(string $key): JobSourceProvider
    {
        $entry = $this->config()[$key] ?? null;

        if ($entry === null) {
            throw new InvalidArgumentException(
                "No job source provider registered under '{$key}'. Registered keys: "
                . (($registered = implode(', ', $this->keys())) === '' ? '(none)' : $registered) . '.'
            );
        }

        $class = is_string($entry) ? $entry : ($entry['class'] ?? null);

        if (! is_string($class) || $class === '') {
            throw new InvalidArgumentException(
                "Job source provider '{$key}' has no 'class' configured."
            );
        }

        $provider = $this->container->make($class);

        if (! $provider instanceof JobSourceProvider) {
            throw new InvalidArgumentException(
                "Job source provider '{$key}' ({$class}) must implement "
                . JobSourceProvider::class . '.'
            );
        }

        if ($provider->key() !== $key) {
            throw new InvalidArgumentException(
                "Job source provider '{$key}' is registered under a key its own key() "
                . "disagrees with ('{$provider->key()}'). The two must match — the key is "
                . 'persisted on job_listings.source_key and used for rate-limit counters.'
            );
        }

        return $provider;
    }

    /**
     * All enabled providers, keyed by source key, in config order. This is what
     * the orchestrator iterates.
     *
     * @return array<string, JobSourceProvider>
     */
    public function enabled(): array
    {
        $providers = [];

        foreach ($this->enabledKeys() as $key) {
            $providers[$key] = $this->get($key);
        }

        return $providers;
    }

    /**
     * All configured providers including disabled ones — for diagnostics and
     * for a future settings screen that lists sources a user could turn on.
     *
     * @return array<string, JobSourceProvider>
     */
    public function all(): array
    {
        $providers = [];

        foreach ($this->keys() as $key) {
            $providers[$key] = $this->get($key);
        }

        return $providers;
    }

    /**
     * @return array<string, array{class?: string, enabled?: bool}|string>
     */
    private function config(): array
    {
        $providers = config('job_sources.providers', []);

        return is_array($providers) ? $providers : [];
    }
}
