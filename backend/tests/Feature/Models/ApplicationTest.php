<?php

namespace Tests\Feature\Models;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The auto-apply evidence columns added to `applications` and their model side.
 *
 * Validates: Requirements 9.5, 1C.2
 */
class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Dev Patel',
            'email' => 'dev'.uniqid().'@example.com',
            'password' => 'password',
        ]);
    }

    private function makeListing(): JobListing
    {
        return JobListing::create([
            'api_id' => 'job-'.uniqid(),
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme',
            'location' => 'Toronto, Ontario, Canada',
            'description' => 'Build things.',
            'api_source' => 'greenhouse',
            'source_key' => 'greenhouse',
            'is_active' => true,
            'parsed_skills' => ['php'],
            'application_url' => 'https://boards.greenhouse.io/acme/jobs/1',
            'pipeline_stage' => PipelineStage::Applying,
        ]);
    }

    private function makeCoverLetter(User $user, JobListing $listing): TailoredDocument
    {
        return TailoredDocument::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::CoverLetter,
            'template_key' => 'modern',
            's3_path' => 'tailored/1/cover-letter.pdf',
            'generation_model' => 'anthropic/claude-3.5-sonnet',
            'generation_tier' => 'premium',
            'status' => TailoredDocumentStatus::Rendered,
        ]);
    }

    public function test_the_table_has_the_auto_apply_evidence_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('applications', [
            'tailored_cover_letter_id',
            'apply_adapter_used',
            'automation_log',
        ]));
    }

    public function test_the_new_columns_are_mass_assignable_and_default_to_null(): void
    {
        $fillable = (new Application())->getFillable();

        foreach (['tailored_cover_letter_id', 'apply_adapter_used', 'automation_log'] as $column) {
            $this->assertContains($column, $fillable, "{$column} should be mass-assignable");
        }

        // A manually-tracked application never went through an adapter.
        $manual = Application::create([
            'user_id' => $this->makeUser()->id,
            'job_listing_id' => $this->makeListing()->id,
            'applied_at' => now(),
            'status' => 'applied',
        ])->fresh();

        $this->assertNull($manual->tailored_cover_letter_id);
        $this->assertNull($manual->apply_adapter_used);
        $this->assertNull($manual->automation_log);
        $this->assertNull($manual->tailoredCoverLetter);
    }

    public function test_an_automated_attempt_round_trips_its_adapter_and_log(): void
    {
        $user = $this->makeUser();
        $listing = $this->makeListing();
        $coverLetter = $this->makeCoverLetter($user, $listing);

        $log = [
            'steps' => ['opened_form', 'filled_contact', 'uploaded_resume', 'submitted'],
            'screenshots' => ['applications/7/confirmation.png'],
            'failure_reason' => null,
        ];

        $application = Application::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'tailored_cover_letter_id' => $coverLetter->id,
            'applied_at' => now(),
            'status' => 'applied',
            'apply_adapter_used' => 'greenhouse',
            'automation_log' => $log,
        ])->fresh();

        $this->assertSame('greenhouse', $application->apply_adapter_used);
        $this->assertSame($log, $application->automation_log, 'automation_log must survive the array cast unchanged');
        $this->assertSame($coverLetter->id, $application->tailoredCoverLetter->id);
    }

    public function test_deleting_the_cover_letter_nulls_the_link_but_keeps_the_application(): void
    {
        $user = $this->makeUser();
        $listing = $this->makeListing();
        $coverLetter = $this->makeCoverLetter($user, $listing);

        $application = Application::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'tailored_cover_letter_id' => $coverLetter->id,
            'applied_at' => now(),
            'status' => 'applied',
        ]);

        $coverLetter->delete();

        $reloaded = $application->fresh();

        $this->assertNotNull($reloaded, 'the record that we applied must outlive the document');
        $this->assertNull($reloaded->tailored_cover_letter_id);
    }
}
