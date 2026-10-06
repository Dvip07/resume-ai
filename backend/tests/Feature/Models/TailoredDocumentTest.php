<?php

namespace Tests\Feature\Models;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Services\Tailoring\FabricationFinding;
use App\Services\Tailoring\FabricationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The repurposed `tailored_documents` table, its model, and the `applications`
 * foreign key that survived the rename.
 *
 * Validates: Requirements 6.5, 1C.2, 1C.6, 1C.7
 */
class TailoredDocumentTest extends TestCase
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
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => ['php'],
            'application_url' => 'https://jobs.example.com/careers/1',
            'pipeline_stage' => PipelineStage::Tailoring,
        ]);
    }

    private function makeDocument(User $user, JobListing $listing, array $overrides = []): TailoredDocument
    {
        return TailoredDocument::create(array_merge([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'template_key' => 'modern',
            's3_path' => 'tailored/1/resume.pdf',
            'tex_source_s3_path' => 'tailored/1/resume.tex',
            'generation_model' => 'anthropic/claude-3.5-sonnet',
            'generation_tier' => 'premium',
            'status' => TailoredDocumentStatus::Rendered,
        ], $overrides));
    }

    public function test_the_renamed_table_has_the_designed_columns_and_drops_the_legacy_ones(): void
    {
        $this->assertFalse(Schema::hasTable('tailored_resumes'), 'the legacy table must be gone, not duplicated');
        $this->assertTrue(Schema::hasTable('tailored_documents'));

        $this->assertTrue(Schema::hasColumns('tailored_documents', [
            'id',
            'user_id',
            'job_listing_id',
            'application_id',
            'type',
            'template_key',
            's3_path',
            'tex_source_s3_path',
            'generation_model',
            'generation_tier',
            'fabrication_flags',
            'status',
            'created_at',
            'updated_at',
        ]));

        $this->assertFalse(Schema::hasColumn('tailored_documents', 'file_path'));
        $this->assertFalse(Schema::hasColumn('tailored_documents', 'ai_analysis'));

        $this->assertTrue(Schema::hasColumn('applications', 'tailored_resume_id'));
        $this->assertFalse(Schema::hasColumn('applications', 'tailored_resumes_id'));
    }

    public function test_a_document_round_trips_with_its_enum_casts(): void
    {
        $stored = $this->makeDocument($this->makeUser(), $this->makeListing())->fresh();

        $this->assertSame(TailoredDocumentType::Resume, $stored->type);
        $this->assertSame(TailoredDocumentStatus::Rendered, $stored->status);
        $this->assertSame('modern', $stored->template_key);
        $this->assertSame('tailored/1/resume.tex', $stored->tex_source_s3_path);
        $this->assertNull($stored->fabrication_flags);
        $this->assertNull($stored->application_id);
        $this->assertTrue($stored->isUsable());
    }

    public function test_a_pending_document_starts_with_the_defaults_the_migration_provides(): void
    {
        $user = $this->makeUser();
        $listing = $this->makeListing();

        // What the tailoring job writes before rendering: identity only.
        $pending = TailoredDocument::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
        ])->fresh();

        $this->assertSame(TailoredDocumentType::Resume, $pending->type);
        $this->assertSame(TailoredDocumentStatus::Pending, $pending->status);
        $this->assertSame('', $pending->s3_path);
        $this->assertFalse($pending->isUsable(), 'a pending row has no uploaded PDF to attach');
    }

    public function test_fabrication_flags_round_trip_a_fabrication_report(): void
    {
        $report = new FabricationReport(
            findings: [
                FabricationFinding::unknownEmployer(
                    'Globex',
                    'bullet 3 of role 1 (Acme)',
                    'The document credits an employer the profile never lists.',
                ),
            ],
            datesVerifiable: false,
            texScanned: true,
            truncated: 0,
        );

        $document = $this->makeDocument($this->makeUser(), $this->makeListing());
        $document->recordFabricationReport($report)->save();

        $stored = $document->fresh();

        $this->assertSame($report->flags(), $stored->fabrication_flags, 'flags() must survive the json cast unchanged');
        $this->assertFalse($stored->fabrication_flags['clean']);
        $this->assertTrue($stored->fabrication_flags['tex_scanned']);
        $this->assertSame('Globex', $stored->fabrication_flags['findings'][0]['token']);
    }

    public function test_it_belongs_to_its_user_job_listing_and_application(): void
    {
        $user = $this->makeUser();
        $listing = $this->makeListing();

        $application = Application::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'applied_at' => now(),
            'status' => 'applied',
        ]);

        $document = $this->makeDocument($user, $listing, ['application_id' => $application->id]);

        $this->assertSame($user->id, $document->user->id);
        $this->assertSame($listing->id, $document->jobListing->id);
        $this->assertSame($application->id, $document->application->id);
    }

    public function test_the_applications_foreign_key_still_points_at_the_renamed_table(): void
    {
        $user = $this->makeUser();
        $listing = $this->makeListing();
        $document = $this->makeDocument($user, $listing);

        $application = Application::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'tailored_resume_id' => $document->id,
            'applied_at' => now(),
            'status' => 'applied',
        ]);

        $this->assertSame($document->id, $application->fresh()->tailoredResume->id);

        // The legacy constraint was `onDelete('cascade')`, and it must still be
        // enforced against `tailored_documents` after the rename.
        $document->delete();

        $this->assertNull(Application::find($application->id), 'the cascade must follow the renamed table');
    }

    public function test_scopes_narrow_to_a_type_and_to_rendered_documents(): void
    {
        $user = $this->makeUser();
        $listing = $this->makeListing();

        $resume = $this->makeDocument($user, $listing);
        $this->makeDocument($user, $listing, [
            'type' => TailoredDocumentType::CoverLetter,
            'status' => TailoredDocumentStatus::Failed,
        ]);

        $this->assertSame(1, TailoredDocument::query()->ofType(TailoredDocumentType::Resume)->count());
        $this->assertSame([$resume->id], TailoredDocument::query()->rendered()->pluck('id')->all());
    }

    public function test_the_enums_describe_terminal_and_usable_states(): void
    {
        $this->assertTrue(TailoredDocumentStatus::Rendered->isTerminal());
        $this->assertTrue(TailoredDocumentStatus::Failed->isTerminal());
        $this->assertFalse(TailoredDocumentStatus::Pending->isTerminal());
        $this->assertFalse(TailoredDocumentStatus::Failed->isUsable());

        $this->assertSame('tailored_resume_id', TailoredDocumentType::Resume->applicationForeignKey());
        $this->assertSame('tailored_cover_letter_id', TailoredDocumentType::CoverLetter->applicationForeignKey());
    }
}
