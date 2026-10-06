<?php

namespace Tests\Unit\Services\JobSources;

use App\Services\JobSources\JobSearchQuery;
use App\Services\JobSources\JobSourceProvider;
use App\Services\JobSources\JobSourceRegistry;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The registry is what makes Requirement 3.2 hold: discovery resolves sources
 * from config, so adding one (tasks 8.2-8.5) is a class plus a config line.
 * These tests use throwaway providers rather than the real ones, which is the
 * point — the registry knows nothing about any specific source.
 */
class JobSourceRegistryTest extends TestCase
{
    private function registry(): JobSourceRegistry
    {
        return $this->app->make(JobSourceRegistry::class);
    }

    public function test_it_resolves_the_providers_shipped_in_config(): void
    {
        // Adzuna is registered as of task 8.2; the rest arrive in 8.3-8.5.
        // Resolving through the real config also proves each shipped provider's
        // key() agrees with the key it is registered under.
        $registry = $this->registry();

        $this->assertContains('adzuna', $registry->keys());
        $this->assertInstanceOf(
            \App\Services\JobSources\Providers\AdzunaJobSourceProvider::class,
            $registry->get('adzuna')
        );
    }

    public function test_it_resolves_enabled_providers_in_config_order(): void
    {
        config(['job_sources.providers' => [
            'fake_a' => ['class' => FakeProviderA::class],
            'fake_b' => ['class' => FakeProviderB::class, 'enabled' => false],
        ]]);

        $registry = $this->registry();

        $this->assertSame(['fake_a', 'fake_b'], $registry->keys());
        $this->assertSame(['fake_a'], $registry->enabledKeys());
        $this->assertTrue($registry->isEnabled('fake_a'));
        $this->assertFalse($registry->isEnabled('fake_b'));
        $this->assertTrue($registry->has('fake_b'));

        $enabled = $registry->enabled();
        $this->assertSame(['fake_a'], array_keys($enabled));
        $this->assertInstanceOf(FakeProviderA::class, $enabled['fake_a']);

        // Disabled providers are still resolvable by key, and listed by all().
        $this->assertSame(['fake_a', 'fake_b'], array_keys($registry->all()));
        $this->assertInstanceOf(FakeProviderB::class, $registry->get('fake_b'));
    }

    public function test_providers_are_enabled_unless_explicitly_disabled(): void
    {
        config(['job_sources.providers' => ['fake_a' => ['class' => FakeProviderA::class]]]);

        $this->assertSame(['fake_a'], $this->registry()->enabledKeys());
    }

    /** Tests (and future per-user overrides) can swap a source by binding its class. */
    public function test_container_bindings_are_honoured(): void
    {
        config(['job_sources.providers' => ['fake_a' => ['class' => FakeProviderA::class]]]);
        $this->app->bind(FakeProviderA::class, fn () => new FakeProviderA());

        $this->assertInstanceOf(FakeProviderA::class, $this->registry()->get('fake_a'));
    }

    public function test_unknown_key_fails_with_the_registered_keys_listed(): void
    {
        config(['job_sources.providers' => ['fake_a' => ['class' => FakeProviderA::class]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_a');

        $this->registry()->get('nope');
    }

    public function test_a_class_that_is_not_a_provider_is_rejected(): void
    {
        config(['job_sources.providers' => ['fake_a' => ['class' => NotAProvider::class]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(JobSourceProvider::class);

        $this->registry()->get('fake_a');
    }

    /**
     * A key mismatch would write rows under one `source_key` while
     * rate-limiting under another, so it fails loudly at resolution.
     */
    public function test_key_mismatch_between_config_and_provider_is_rejected(): void
    {
        config(['job_sources.providers' => ['wrong_key' => ['class' => FakeProviderA::class]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fake_a');

        $this->registry()->get('wrong_key');
    }

    public function test_a_missing_class_entry_is_rejected(): void
    {
        config(['job_sources.providers' => ['fake_a' => ['enabled' => true]]]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'class'");

        $this->registry()->get('fake_a');
    }
}

class FakeProviderA implements JobSourceProvider
{
    public function key(): string
    {
        return 'fake_a';
    }

    public function search(JobSearchQuery $query): Collection
    {
        return collect();
    }
}

class FakeProviderB implements JobSourceProvider
{
    public function key(): string
    {
        return 'fake_b';
    }

    public function search(JobSearchQuery $query): Collection
    {
        return collect();
    }
}

class NotAProvider {}
