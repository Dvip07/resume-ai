<?php

namespace Tests\Unit\Services;

use App\Exceptions\ModelRouterException;
use App\Services\ComplexityScorer;
use App\Services\ModelRouterService;
use App\Services\ModelTierContext;
use Tests\TestCase;

/**
 * Tier selection (task 7.3, Requirements 4.3 and 4.4): salary thresholds when
 * the listing has pay data, the complexity heuristic when it doesn't.
 *
 * Table-driven per design.md's testing strategy. No HTTP involved — selectTier
 * is exercised directly through a subclass that widens its visibility, so a
 * routing regression can't hide behind a transport fake.
 */
class ModelTierSelectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pin the knobs the heuristic reads so assertions don't move with .env.
        config([
            'services.openrouter.default_tier' => 'cheap',
            'services.openrouter.salary_thresholds' => ['standard' => 90000, 'premium' => 150000],
            'services.openrouter.complexity_thresholds' => ['standard' => 40, 'premium' => 75],
            'services.openrouter.complexity' => [
                'weights' => ['jd_length' => 40, 'requirement_count' => 30, 'skill_gap' => 30],
                'jd_length_saturation' => 6000,
                'requirement_count_saturation' => 20,
                'skill_gap_saturation' => 10,
            ],
        ]);
    }

    private function router(): ModelRouterService
    {
        return new class extends ModelRouterService
        {
            public function tierFor(ModelTierContext $context): string
            {
                return $this->selectTier($context);
            }

            /**
             * @return array<int, string>
             */
            public function modelsFor(string $tier): array
            {
                return $this->candidateModels('jd_rating', $tier);
            }
        };
    }

    private function scorer(): ComplexityScorer
    {
        return new ComplexityScorer();
    }

    /**
     * @return array<string, array{0: int|null, 1: int|null, 2: string}>
     */
    public static function salaryCases(): array
    {
        //            salaryMin, salaryMax, expected tier
        return [
            'far below the lowest threshold stays at the default' => [40000, 45000, 'cheap'],
            'one dollar below standard stays at the default' => [null, 89999, 'cheap'],
            'exactly at the standard threshold escalates' => [null, 90000, 'standard'],
            'between the thresholds is standard' => [100000, 120000, 'standard'],
            'one dollar below premium is still standard' => [null, 149999, 'standard'],
            'exactly at the premium threshold escalates' => [null, 150000, 'premium'],
            'above the premium threshold is premium' => [180000, 220000, 'premium'],
            'min only is used when no max is given' => [95000, null, 'standard'],
            'max only is used when no min is given' => [null, 160000, 'premium'],
            'the top of a wide range decides the tier' => [60000, 160000, 'premium'],
            'a zero salary is not an escalation signal' => [0, 0, 'cheap'],
            'a negative figure cannot escalate' => [-500000, null, 'cheap'],
        ];
    }

    /**
     * @dataProvider salaryCases
     */
    public function test_salary_thresholds_pick_the_highest_matching_tier(
        ?int $salaryMin,
        ?int $salaryMax,
        string $expected,
    ): void {
        $context = new ModelTierContext(salaryMin: $salaryMin, salaryMax: $salaryMax, currency: 'USD');

        $this->assertSame($expected, $this->router()->tierFor($context));
    }

    /**
     * A high complexity score must not sneak a low-paying role into premium:
     * once salary is known, salary decides (Requirement 4.3).
     */
    public function test_salary_data_takes_precedence_over_the_complexity_score(): void
    {
        $context = new ModelTierContext(salaryMin: 40000, salaryMax: 50000, complexityScore: 100);

        $this->assertSame('cheap', $this->router()->tierFor($context));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function complexityCases(): array
    {
        //            complexityScore, expected tier
        return [
            'a zero-signal context stays cheap' => [0, 'cheap'],
            'just below the standard boundary is cheap' => [39, 'cheap'],
            'exactly on the standard boundary escalates' => [40, 'standard'],
            'mid-band is standard' => [60, 'standard'],
            'just below the premium boundary is standard' => [74, 'standard'],
            'exactly on the premium boundary escalates' => [75, 'premium'],
            'a saturated score is premium' => [100, 'premium'],
        ];
    }

    /**
     * @dataProvider complexityCases
     */
    public function test_complexity_thresholds_are_used_when_no_salary_is_known(
        int $score,
        string $expected,
    ): void {
        $context = new ModelTierContext(complexityScore: $score);

        $this->assertSame($expected, $this->router()->tierFor($context));
    }

    public function test_an_unknown_context_never_lands_on_the_most_expensive_tier(): void
    {
        $this->assertSame('cheap', $this->router()->tierFor(ModelTierContext::unknown()));
    }

    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int}>
     */
    public static function scoreCases(): array
    {
        //            jdLength, requirementCount, skillGap, expected score
        return [
            // Nothing measured — the caller knows nothing, so no escalation.
            'no signals score zero' => [0, 0, 0, 0],
            // All three saturated: 40 + 30 + 30.
            'all signals saturated score 100' => [6000, 20, 10, 100],
            // Beyond saturation is clamped, not compounded.
            'signals past saturation are clamped' => [60000, 200, 100, 100],
            // 40 * 0.5 = 20.
            'half-length JD alone scores its half weight' => [3000, 0, 0, 20],
            // 30 * (10/20) = 15.
            'requirements alone are capped by their weight' => [0, 10, 0, 15],
            // 30 * (5/10) = 15.
            'skill gap alone is capped by its weight' => [0, 0, 5, 15],
            // 40*0.5 + 30*0.5 + 30*0.5 = 50, the standard band.
            'evenly middling signals land mid-scale' => [3000, 10, 5, 50],
            // 40*(4000/6000)=26.67 + 30*0.75=22.5 + 30*0.8=24 → 73.17 → 73.
            'a heavy JD lands just under the premium boundary' => [4000, 15, 8, 73],
            // Same but a longer JD: 40*(5000/6000)=33.33 + 22.5 + 24 → 79.83 → 80.
            'a longer JD crosses into premium' => [5000, 15, 8, 80],
            // Negative inputs are treated as absent rather than subtracting.
            'negative signals contribute nothing' => [-5000, -3, -1, 0],
        ];
    }

    /**
     * @dataProvider scoreCases
     */
    public function test_complexity_score_is_derived_from_the_raw_signals(
        int $jdLength,
        int $requirementCount,
        int $skillGap,
        int $expected,
    ): void {
        $this->assertSame($expected, $this->scorer()->score($jdLength, $requirementCount, $skillGap));
    }

    public function test_from_signals_wires_the_scorer_into_the_context(): void
    {
        $context = ModelTierContext::fromSignals(
            currency: 'USD',
            jdLength: 3000,
            requirementCount: 10,
            skillGapSize: 5,
        );

        $this->assertFalse($context->hasSalary());
        $this->assertSame(50, $context->complexityScore);
        $this->assertSame('standard', $this->router()->tierFor($context));
    }

    public function test_from_signals_keeps_salary_data_alongside_the_score(): void
    {
        $context = ModelTierContext::fromSignals(
            salaryMin: 100000,
            salaryMax: 160000,
            currency: 'USD',
            jdLength: 500,
        );

        $this->assertTrue($context->hasSalary());
        $this->assertSame(160000, $context->salaryBasis());
        $this->assertSame(3, $context->complexityScore);
        // Salary wins over the near-zero complexity score.
        $this->assertSame('premium', $this->router()->tierFor($context));
    }

    /*
    |--------------------------------------------------------------------------
    | Config-driven, not hard-coded (Requirement 4.2)
    |--------------------------------------------------------------------------
    */

    public function test_salary_thresholds_come_from_config(): void
    {
        $context = new ModelTierContext(salaryMax: 60000);

        $this->assertSame('cheap', $this->router()->tierFor($context));

        config(['services.openrouter.salary_thresholds' => ['standard' => 50000, 'premium' => 55000]]);

        $this->assertSame('premium', $this->router()->tierFor($context));
    }

    public function test_complexity_thresholds_come_from_config(): void
    {
        $context = new ModelTierContext(complexityScore: 30);

        $this->assertSame('cheap', $this->router()->tierFor($context));

        config(['services.openrouter.complexity_thresholds' => ['standard' => 10, 'premium' => 25]]);

        $this->assertSame('premium', $this->router()->tierFor($context));
    }

    public function test_complexity_weights_come_from_config(): void
    {
        // Default weights: 40 * (3000/6000) = 20.
        $this->assertSame(20, $this->scorer()->score(jdLength: 3000));

        // Skill gap now carries everything, so a JD-length-only signal is inert.
        config([
            'services.openrouter.complexity.weights' => [
                'jd_length' => 0,
                'requirement_count' => 0,
                'skill_gap' => 100,
            ],
        ]);

        $this->assertSame(0, $this->scorer()->score(jdLength: 3000));
        $this->assertSame(50, $this->scorer()->score(jdLength: 3000, skillGapSize: 5));
    }

    public function test_saturation_points_come_from_config(): void
    {
        $this->assertSame(20, $this->scorer()->score(jdLength: 3000));

        // A shorter JD now counts as maximally long, so it earns its full weight.
        config(['services.openrouter.complexity.jd_length_saturation' => 1500]);

        $this->assertSame(40, $this->scorer()->score(jdLength: 3000));
    }

    /**
     * Weights are relative, so scaling them all changes nothing — the score is
     * normalized by the total weight rather than assuming they sum to 100.
     */
    public function test_weights_are_relative_not_absolute(): void
    {
        $baseline = $this->scorer()->score(3000, 10, 5);

        config([
            'services.openrouter.complexity.weights' => [
                'jd_length' => 4,
                'requirement_count' => 3,
                'skill_gap' => 3,
            ],
        ]);

        $this->assertSame($baseline, $this->scorer()->score(3000, 10, 5));
    }

    public function test_thresholds_may_be_listed_in_any_order_and_extra_tiers_are_honoured(): void
    {
        config([
            'services.openrouter.salary_thresholds' => [
                'premium' => 150000,
                'standard' => 90000,
            ],
        ]);

        $this->assertSame('premium', $this->router()->tierFor(new ModelTierContext(salaryMax: 200000)));
        $this->assertSame('standard', $this->router()->tierFor(new ModelTierContext(salaryMax: 90000)));
    }

    /**
     * The other half of Requirement 4.2: the selected tier is resolved to
     * models through config, in the order config lists them, so swapping a
     * model is a config edit and nothing more.
     */
    public function test_the_model_list_for_a_tier_is_read_from_config_in_order(): void
    {
        config([
            'services.openrouter.tiers' => [
                'cheap' => ['vendor/cheap-1', 'vendor/cheap-2'],
                'premium' => ['vendor/premium-1'],
            ],
        ]);

        $this->assertSame(['vendor/cheap-1', 'vendor/cheap-2'], $this->router()->modelsFor('cheap'));
        $this->assertSame(['vendor/premium-1'], $this->router()->modelsFor('premium'));

        // Reordering config reorders preference, with no code change.
        config(['services.openrouter.tiers.cheap' => ['vendor/cheap-2', 'vendor/cheap-1']]);

        $this->assertSame(['vendor/cheap-2', 'vendor/cheap-1'], $this->router()->modelsFor('cheap'));
    }

    public function test_a_tier_with_no_usable_models_configured_throws_instead_of_guessing(): void
    {
        config(['services.openrouter.tiers' => ['cheap' => ['', null, 42]]]);

        $this->expectException(ModelRouterException::class);
        $this->expectExceptionMessage('No OpenRouter models configured for tier [cheap].');

        $this->router()->modelsFor('cheap');
    }

    public function test_a_tier_missing_from_config_throws_instead_of_falling_back_silently(): void
    {
        config(['services.openrouter.tiers' => ['cheap' => ['vendor/cheap-1']]]);

        $this->expectException(ModelRouterException::class);

        $this->router()->modelsFor('premium');
    }

    public function test_malformed_or_missing_thresholds_degrade_to_the_default_tier(): void
    {
        config(['services.openrouter.salary_thresholds' => null]);

        $this->assertSame('cheap', $this->router()->tierFor(new ModelTierContext(salaryMax: 500000)));

        config(['services.openrouter.salary_thresholds' => ['standard' => 'ninety thousand']]);

        $this->assertSame('cheap', $this->router()->tierFor(new ModelTierContext(salaryMax: 500000)));
    }
}
