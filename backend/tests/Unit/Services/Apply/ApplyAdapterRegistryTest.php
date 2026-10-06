<?php

namespace Tests\Unit\Services\Apply;

use App\Services\Apply\Adapters\GreenhouseApplyAdapter;
use App\Services\Apply\Adapters\LeverApplyAdapter;
use App\Services\Apply\Adapters\WorkdayApplyAdapter;
use App\Services\Apply\ApplyAdapterRegistry;
use Tests\TestCase;

/**
 * Adapter selection (task 15.7, Requirement 9.1): first `supports()` match in
 * registration order wins, and nothing matching is a normal answer.
 *
 * Validates: Requirements 9.1
 */
class ApplyAdapterRegistryTest extends TestCase
{
    private function registry(): ApplyAdapterRegistry
    {
        return $this->app->make(ApplyAdapterRegistry::class);
    }

    public function test_it_resolves_each_shipped_adapter_from_the_application_url(): void
    {
        $registry = $this->registry();

        $this->assertInstanceOf(
            GreenhouseApplyAdapter::class,
            $registry->resolve('https://boards.greenhouse.io/acme/jobs/4242')
        );
        $this->assertInstanceOf(
            LeverApplyAdapter::class,
            $registry->resolve('https://jobs.lever.co/acme/8f2c-1')
        );
        $this->assertInstanceOf(
            WorkdayApplyAdapter::class,
            $registry->resolve('https://acme.wd1.myworkdayjobs.com/en-US/careers/job/Engineer_R-1')
        );
    }

    public function test_an_unknown_careers_page_resolves_to_nothing(): void
    {
        $registry = $this->registry();

        $this->assertNull($registry->resolve('https://careers.acme.example/jobs/4242'));
        $this->assertNull($registry->resolve(''));
        $this->assertNull($registry->resolve('not-a-url'));
    }

    public function test_registration_order_decides_between_two_adapters_that_both_match(): void
    {
        // Lever first: it now gets asked before Greenhouse, and a URL both
        // could claim goes to whoever is higher in the list.
        config(['apply.registry' => [
            'lever' => LeverApplyAdapter::class,
            'greenhouse' => GreenhouseApplyAdapter::class,
        ]]);
        config(['apply.adapters.lever.hosts' => ['lever.co', 'greenhouse.io']]);

        $adapter = $this->registry()->resolve('https://boards.greenhouse.io/acme/jobs/4242');

        $this->assertInstanceOf(LeverApplyAdapter::class, $adapter);
    }

    public function test_an_unusable_registry_entry_is_skipped_rather_than_fatal(): void
    {
        config(['apply.registry' => [
            'bogus' => 'App\\Services\\Apply\\Adapters\\NoSuchAdapter',
            'not_an_adapter' => self::class,
            'greenhouse' => GreenhouseApplyAdapter::class,
        ]]);

        $registry = $this->registry();

        $this->assertInstanceOf(
            GreenhouseApplyAdapter::class,
            $registry->resolve('https://boards.greenhouse.io/acme/jobs/4242')
        );
        $this->assertSame(['greenhouse'], array_keys($registry->all()));
    }

    public function test_it_names_the_adapter_by_its_config_key(): void
    {
        $registry = $this->registry();
        $adapter = $registry->resolve('https://jobs.lever.co/acme/8f2c-1');

        $this->assertSame('lever', $registry->keyFor($adapter));
    }
}
