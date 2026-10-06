<?php

namespace Tests\Feature\Api;

use App\Enums\PipelineStage;
use App\Enums\RecommendedAction;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\JobScore;
use App\Models\TailoredDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Feature tests for the applications read API
 * (App\Http\Controllers\Api\ApplicationController / routes/api.php).
 *
 * Validates: Requirements 10.1, 10.2, 10.4
 */
class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    private function makeJobListing(): JobListing
    {
        return JobListing::create([
            'api_id' => 'job-'.uniqid(),
            'title' => 'Software Engineer',
            'company' => 'Acme Inc',
            'location' => 'Remote',
            'description' => 'Build things.',
            'api_source' => 'adzuna',
            'posted_at' => now(),
            'is_active' => true,
            'parsed_skills' => ['php', 'laravel'],
            'application_url' => 'https://example.com/apply',
        ]);
    }

    private function makeApplication(User $user, JobListing $jobListing, array $overrides = []): Application
    {
        return Application::create(array_merge([
            'user_id' => $user->id,
            'job_listing_id' => $jobListing->id,
            'applied_at' => now(),
            'status' => 'applied',
        ], $overrides));
    }

    public function test_index_without_authorization_header_returns_401(): void
    {
        $this->getJson('/api/applications')->assertStatus(401);
    }

    public function test_show_without_authorization_header_returns_401(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing());

        $this->getJson("/api/applications/{$application->id}")->assertStatus(401);
    }

    public function test_index_returns_only_the_authenticated_users_applications_paginated(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->makeApplication($user, $this->makeJobListing(), ['status' => 'applied']);
        $this->makeApplication($otherUser, $this->makeJobListing(), ['status' => 'applied']);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/applications');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'per_page'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $user->id);
    }

    public function test_show_returns_an_application_owned_by_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing());

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $application->id)
            ->assertJsonPath('user_id', $user->id);
    }

    public function test_show_returns_404_for_another_users_application(): void
    {
        $owner = User::factory()->create();
        $application = $this->makeApplication($owner, $this->makeJobListing());

        $otherUser = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($otherUser))
            ->getJson("/api/applications/{$application->id}");

        $response->assertStatus(404);
    }

    public function test_show_returns_404_for_a_nonexistent_application(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/applications/999999');

        $response->assertStatus(404);
    }

    public function test_update_without_authorization_header_returns_401(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing());

        $this->patchJson("/api/applications/{$application->id}", [
            'response_status' => 'interviewing',
        ])->assertStatus(401);
    }

    public function test_update_records_manual_status_without_touching_automation_status(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing(), [
            'status' => 'applied',
            'metadata' => ['adapter_notes' => 'submitted via greenhouse'],
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/applications/{$application->id}", [
                'response_status' => 'interviewing',
                'response_note' => 'Phone screen on Friday.',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('response_status', 'interviewing')
            // The automation-driven column is untouched (Requirement 10.4).
            ->assertJsonPath('status', 'applied')
            ->assertJsonPath('metadata.manual_status_history.0.response_status', 'interviewing')
            ->assertJsonPath('metadata.manual_status_history.0.note', 'Phone screen on Friday.')
            ->assertJsonPath('metadata.manual_status_history.0.source', 'manual')
            // Pre-existing metadata survives the patch.
            ->assertJsonPath('metadata.adapter_notes', 'submitted via greenhouse');

        $application->refresh();
        $this->assertSame('interviewing', $application->response_status);
        $this->assertSame('applied', $application->status);
        $this->assertCount(1, $application->metadata['manual_status_history']);
    }

    public function test_update_appends_each_manual_update_to_the_history(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing());
        $headers = $this->authHeader($user);

        $this->withHeaders($headers)
            ->patchJson("/api/applications/{$application->id}", ['response_status' => 'interviewing'])
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->patchJson("/api/applications/{$application->id}", ['response_status' => 'rejected'])
            ->assertStatus(200);

        $application->refresh();
        $history = $application->metadata['manual_status_history'];

        $this->assertCount(2, $history);
        $this->assertSame('interviewing', $history[0]['response_status']);
        $this->assertSame('rejected', $history[1]['response_status']);
        $this->assertSame('rejected', $application->response_status);
    }

    public function test_update_rejects_an_attempt_to_set_the_automation_driven_status(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing(), ['status' => 'pending']);

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/applications/{$application->id}", [
                'response_status' => 'rejected',
                'status' => 'applied',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame('pending', $application->refresh()->status);
        $this->assertNull($application->response_status);
    }

    public function test_update_rejects_an_unknown_response_status(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing());

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/applications/{$application->id}", [
                'response_status' => 'ghosted_me',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('response_status');

        $this->assertNull($application->refresh()->response_status);
    }

    public function test_update_requires_a_response_status(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing());

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/applications/{$application->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('response_status');
    }

    public function test_update_returns_404_for_another_users_application(): void
    {
        $owner = User::factory()->create();
        $application = $this->makeApplication($owner, $this->makeJobListing());

        $otherUser = User::factory()->create();

        $this->withHeaders($this->authHeader($otherUser))
            ->patchJson("/api/applications/{$application->id}", [
                'response_status' => 'rejected',
            ])
            ->assertStatus(404);

        $this->assertNull($application->refresh()->response_status);
    }

    /* ------------------------------------------- dashboard payload (14.3) */

    private function makeScore(User $user, JobListing $listing, int $stars, int $attempt = 1): JobScore
    {
        return JobScore::create([
            'job_listing_id' => $listing->id,
            'user_id' => $user->id,
            'attempt_number' => $attempt,
            'fit_analysis' => ['matched_skills' => ['php']],
            'stars' => $stars,
            'rationale' => "Rated {$stars} on attempt {$attempt}.",
            'recommended_action' => RecommendedAction::AutoApply,
            'raw_prompt_1_output' => '{}',
            'raw_prompt_2_output' => '{}',
        ]);
    }

    private function makeDocument(User $user, JobListing $listing, array $overrides = []): TailoredDocument
    {
        return TailoredDocument::create(array_merge([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'template_key' => 'modern',
            's3_path' => 'users/'.$user->id.'/tailored-documents/1/resume.pdf',
            'generation_model' => 'test/model',
            'generation_tier' => 'standard',
            'status' => TailoredDocumentStatus::Rendered,
        ], $overrides));
    }

    public function test_index_rows_carry_the_listing_pipeline_stage_and_the_latest_star_rating(): void
    {
        $user = User::factory()->create();
        $listing = $this->makeJobListing();
        $listing->update(['pipeline_stage' => PipelineStage::Applied]);

        // Two attempts: the dashboard must show the newer one (Requirement 5.6).
        $this->makeScore($user, $listing, 3, 1);
        $this->makeScore($user, $listing, 5, 2);

        $this->makeApplication($user, $listing);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/applications')
            ->assertStatus(200)
            ->assertJsonPath('data.0.job_listing.title', 'Software Engineer')
            ->assertJsonPath('data.0.job_listing.pipeline_stage', 'applied')
            ->assertJsonPath('data.0.score.stars', 5)
            ->assertJsonPath('data.0.score.attempt_number', 2)
            ->assertJsonPath('data.0.needs_review', false)
            ->assertJsonPath('data.0.review', null)
            // The raw columns the API already returned are still there.
            ->assertJsonPath('data.0.status', 'applied');
    }

    public function test_index_does_not_leak_another_users_score_for_a_shared_listing(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $listing = $this->makeJobListing();

        $this->makeScore($otherUser, $listing, 5);
        $this->makeApplication($user, $listing);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/applications')
            ->assertStatus(200)
            ->assertJsonPath('data.0.score', null);
    }

    public function test_show_returns_the_jd_score_rationale_automation_log_and_a_signed_document_link(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $listing = $this->makeJobListing();
        $this->makeScore($user, $listing, 4);

        $application = $this->makeApplication($user, $listing, [
            'automation_log' => [
                'adapter' => 'greenhouse',
                'steps' => ['filled name', 'uploaded resume'],
            ],
        ]);

        $document = $this->makeDocument($user, $listing, ['application_id' => $application->id]);
        Storage::disk('s3')->put($document->s3_path, '%PDF-1.4');
        $application->update(['tailored_resume_id' => $document->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('job_listing.description', 'Build things.')
            ->assertJsonPath('score.stars', 4)
            ->assertJsonPath('score.rationale', 'Rated 4 on attempt 1.')
            ->assertJsonPath('score.fit_analysis.matched_skills.0', 'php')
            ->assertJsonPath('automation_log.adapter', 'greenhouse')
            ->assertJsonPath('documents.0.type', 'resume')
            ->assertJsonPath('documents.0.download_error', null);

        $this->assertNotNull($response->json('documents.0.download_url'));
    }

    public function test_show_reports_an_unsignable_document_instead_of_failing_the_request(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $listing = $this->makeJobListing();
        $application = $this->makeApplication($user, $listing);

        // Never rendered: no stored path to sign.
        $document = $this->makeDocument($user, $listing, [
            's3_path' => '',
            'status' => TailoredDocumentStatus::Pending,
        ]);
        $application->update(['tailored_resume_id' => $document->id]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/applications/{$application->id}");

        $response->assertStatus(200)
            ->assertJsonPath('documents.0.download_url', null);

        $this->assertNotNull($response->json('documents.0.download_error'));
    }

    public function test_needs_review_surfaces_unanswered_questions_as_the_call_to_action(): void
    {
        $user = User::factory()->create();
        $listing = $this->makeJobListing();
        $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);

        $application = $this->makeApplication($user, $listing, [
            'status' => 'needs_review',
            'automation_log' => [
                'unanswered_questions' => [
                    'Are you legally authorised to work in Canada?',
                    ['question' => 'Expected salary?'],
                ],
            ],
        ]);

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/applications/{$application->id}")
            ->assertStatus(200)
            ->assertJsonPath('needs_review', true)
            ->assertJsonPath('review.kind', 'unanswered_questions')
            ->assertJsonPath('review.questions.0', 'Are you legally authorised to work in Canada?')
            ->assertJsonPath('review.questions.1', 'Expected salary?');
    }

    public function test_needs_review_surfaces_a_fabrication_finding_as_the_call_to_action(): void
    {
        Storage::fake('s3');

        $user = User::factory()->create();
        $listing = $this->makeJobListing();
        $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);

        $application = $this->makeApplication($user, $listing);

        $document = $this->makeDocument($user, $listing, [
            'fabrication_flags' => [
                'findings' => [
                    ['type' => 'employer_not_in_source', 'value' => 'Globex'],
                ],
            ],
        ]);
        $application->update(['tailored_resume_id' => $document->id]);

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/applications/{$application->id}")
            ->assertStatus(200)
            ->assertJsonPath('review.kind', 'possible_fabrication')
            ->assertJsonPath('review.documents.0', 'resume');
    }

    public function test_needs_review_without_recorded_evidence_still_gets_a_call_to_action(): void
    {
        $user = User::factory()->create();
        $listing = $this->makeJobListing();
        $listing->update(['pipeline_stage' => PipelineStage::NeedsReview]);

        $this->makeApplication($user, $listing);

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/applications')
            ->assertStatus(200)
            ->assertJsonPath('data.0.needs_review', true)
            ->assertJsonPath('data.0.review.kind', 'unspecified');
    }

    public function test_update_response_includes_the_manual_status_history(): void
    {
        $user = User::factory()->create();
        $application = $this->makeApplication($user, $this->makeJobListing());

        $this->withHeaders($this->authHeader($user))
            ->patchJson("/api/applications/{$application->id}", [
                'response_status' => 'offer',
                'response_note' => 'Verbal offer.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('response_status', 'offer')
            ->assertJsonPath('manual_status_history.0.response_status', 'offer')
            ->assertJsonPath('manual_status_history.0.note', 'Verbal offer.');
    }
}
