<?php

namespace Tests\Unit;

use App\Enums\PipelineStage;
use App\Models\JobListing;
use PHPUnit\Framework\TestCase;

/**
 * Covers the model side of the discovery/pipeline/salary columns added by
 * 2026_09_05_002808_add_source_key_dedupe_hash_pipeline_stage_salary_to_job_listings_table.
 */
class JobListingModelTest extends TestCase
{
    public function test_new_discovery_columns_are_fillable(): void
    {
        $fillable = (new JobListing())->getFillable();

        foreach (['source_key', 'dedupe_hash', 'pipeline_stage', 'salary_min', 'salary_max', 'currency'] as $column) {
            $this->assertContains($column, $fillable, "{$column} should be mass-assignable");
        }
    }

    public function test_pipeline_stage_is_cast_to_the_enum(): void
    {
        $casts = (new JobListing())->getCasts();

        $this->assertArrayHasKey('pipeline_stage', $casts);
        $this->assertSame(PipelineStage::class, $casts['pipeline_stage']);
    }

    public function test_pipeline_stage_cast_round_trips_string_values(): void
    {
        $job = new JobListing(['pipeline_stage' => 'discovered']);

        $this->assertSame(PipelineStage::Discovered, $job->pipeline_stage);
        $this->assertSame('discovered', $job->getAttributes()['pipeline_stage']);
    }

    public function test_salary_bounds_are_cast_to_integers(): void
    {
        $job = new JobListing(['salary_min' => '90000', 'salary_max' => '120000', 'currency' => 'USD']);

        $this->assertSame(90000, $job->salary_min);
        $this->assertSame(120000, $job->salary_max);
        $this->assertTrue($job->hasSalary());
    }

    public function test_has_salary_is_false_without_any_bound(): void
    {
        $this->assertFalse((new JobListing())->hasSalary());
    }

    public function test_only_stages_with_no_queued_work_are_terminal(): void
    {
        foreach ([PipelineStage::Applied, PipelineStage::Failed, PipelineStage::NeedsReview, PipelineStage::StoreOnly] as $stage) {
            $this->assertTrue($stage->isTerminal(), "{$stage->value} should be terminal");
        }

        foreach ([
            PipelineStage::Discovered,
            PipelineStage::Enriching,
            PipelineStage::Scored,
            PipelineStage::Tailoring,
            PipelineStage::Tailored,
            PipelineStage::Applying,
        ] as $stage) {
            $this->assertFalse($stage->isTerminal(), "{$stage->value} should not be terminal");
        }
    }
}
