<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the shape of config('services.openrouter'), which ModelRouterService
 * (task 7.2 onward) reads directly. Requirements 4.1, 4.2, 4.7.
 */
class OpenRouterConfigTest extends TestCase
{
    public function test_config_block_exposes_the_keys_model_router_depends_on(): void
    {
        $config = config('services.openrouter');

        $this->assertIsArray($config, 'services.openrouter must be defined');

        foreach ([
            'api_key',
            'base_url',
            'timeout',
            'tiers',
            'default_tier',
            'salary_thresholds',
            'complexity_thresholds',
            'complexity',
        ] as $key) {
            $this->assertArrayHasKey($key, $config);
        }

        $this->assertSame('https://openrouter.ai/api/v1', $config['base_url']);
        $this->assertGreaterThan(0, $config['timeout']);
    }

    public function test_every_tier_maps_to_an_ordered_list_of_at_least_two_models(): void
    {
        $tiers = config('services.openrouter.tiers');

        // The three cost tiers, in escalating order, plus `web_search` — not a
        // cost tier but a capability one, pinned by name by the LLM job-search
        // source (task 8.5) because no salary or complexity signal can express
        // "this model must be able to search the live web".
        $this->assertSame(['cheap', 'standard', 'premium', 'web_search'], array_keys($tiers));

        foreach ($tiers as $tier => $models) {
            // A list, so index 0 is the preferred model and the rest are
            // same-tier fallbacks in order (Requirement 4.5).
            $this->assertSame(array_values($models), $models, "{$tier} models must be an ordered list");
            $this->assertGreaterThanOrEqual(2, count($models), "{$tier} needs a fallback model");

            foreach ($models as $model) {
                // OpenRouter slugs are always `vendor/model`, optionally with a
                // `:variant` suffix (`:online` enables OpenRouter's web plugin).
                $this->assertMatchesRegularExpression(
                    '#^[a-z0-9._-]+/[a-z0-9._-]+(:[a-z0-9._-]+)?$#i',
                    $model
                );
            }
        }
    }

    public function test_default_tier_is_a_known_non_premium_tier(): void
    {
        $default = config('services.openrouter.default_tier');
        $tiers = config('services.openrouter.tiers');

        $this->assertArrayHasKey($default, $tiers);
        // Requirement 4.4: never silently default to the most expensive tier.
        $this->assertNotSame('premium', $default);
    }

    public function test_salary_and_complexity_thresholds_escalate_in_order(): void
    {
        $salary = config('services.openrouter.salary_thresholds');
        $complexity = config('services.openrouter.complexity_thresholds');

        foreach ([$salary, $complexity] as $thresholds) {
            $this->assertSame(['standard', 'premium'], array_keys($thresholds));
            $this->assertLessThan(
                $thresholds['premium'],
                $thresholds['standard'],
                'the premium threshold must sit above the standard one'
            );

            foreach ($thresholds as $tier => $value) {
                $this->assertIsInt($value, "{$tier} threshold must be an int");
                $this->assertGreaterThan(0, $value);
            }
        }
    }

    public function test_complexity_weights_sum_to_the_score_scale(): void
    {
        $complexity = config('services.openrouter.complexity');

        $this->assertSame(
            ['jd_length', 'requirement_count', 'skill_gap'],
            array_keys($complexity['weights'])
        );

        // Thresholds are expressed on a 0-100 scale, so the weights must too.
        $this->assertSame(100, array_sum($complexity['weights']));

        foreach (['jd_length_saturation', 'requirement_count_saturation', 'skill_gap_saturation'] as $key) {
            $this->assertArrayHasKey($key, $complexity);
            $this->assertGreaterThan(0, $complexity[$key], "{$key} must be positive to avoid divide-by-zero");
        }
    }

    /**
     * Requirement 4.2: tier → model mapping lives in config only, so a pricing
     * or availability change is a config edit. A model slug appearing as a
     * literal anywhere under `app/` means some code path has quietly pinned
     * itself to a model.
     */
    public function test_no_configured_model_slug_appears_as_a_literal_in_application_code(): void
    {
        $slugs = collect(config('services.openrouter.tiers'))->flatten()->all();
        $this->assertNotEmpty($slugs);

        $offenders = [];

        foreach ($this->phpFilesUnderApp() as $path) {
            $contents = file_get_contents($path);

            foreach ($slugs as $slug) {
                if (str_contains($contents, $slug)) {
                    $offenders[] = basename($path) . " references {$slug}";
                }
            }
        }

        $this->assertSame([], $offenders, 'model slugs must come from config, not code');
    }

    /**
     * The same guard from the other direction: no `vendor/model`-shaped string
     * literal at all inside the router, so a *future* slug can't be pinned
     * there either.
     */
    public function test_the_router_contains_no_model_slug_shaped_literals(): void
    {
        $vendors = collect(config('services.openrouter.tiers'))
            ->flatten()
            ->map(fn (string $slug) => explode('/', $slug)[0])
            ->unique();

        $contents = file_get_contents(app_path('Services/ModelRouterService.php'));

        foreach ($vendors as $vendor) {
            $this->assertStringNotContainsString(
                "'{$vendor}/",
                $contents,
                "ModelRouterService must not name {$vendor} models directly"
            );
        }
    }

    /**
     * @return array<int, string>
     */
    private function phpFilesUnderApp(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files);

        return $files;
    }

    public function test_api_key_is_env_driven_and_not_hardcoded(): void
    {
        $raw = file_get_contents(config_path('services.php'));

        // Requirement 4.7: the key comes from env only, never a literal.
        $this->assertStringContainsString("env('OPENROUTER_API_KEY')", $raw);

        $key = config('services.openrouter.api_key');
        $this->assertTrue($key === null || is_string($key));
    }

    public function test_example_env_documents_the_key_without_a_value(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('OPENROUTER_API_KEY=', $example);
        // No committed key: the line must be empty.
        $this->assertMatchesRegularExpression('/^OPENROUTER_API_KEY=\s*$/m', $example);
    }
}
