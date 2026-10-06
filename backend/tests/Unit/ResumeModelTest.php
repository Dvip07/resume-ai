<?php

namespace Tests\Unit;

use App\Models\Resume;
use PHPUnit\Framework\TestCase;

class ResumeModelTest extends TestCase
{
    public function test_parsed_data_and_job_analysis_are_cast_to_array(): void
    {
        $resume = new Resume();
        $casts = $resume->getCasts();

        $this->assertArrayHasKey('parsed_data', $casts);
        $this->assertSame('array', $casts['parsed_data']);

        $this->assertArrayHasKey('job_analysis', $casts);
        $this->assertSame('array', $casts['job_analysis']);
    }

    public function test_is_optimized_is_cast_to_boolean(): void
    {
        $resume = new Resume();
        $casts = $resume->getCasts();

        $this->assertArrayHasKey('is_optimized', $casts);
        $this->assertSame('boolean', $casts['is_optimized']);
    }
}
