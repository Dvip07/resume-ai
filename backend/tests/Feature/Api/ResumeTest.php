<?php

namespace Tests\Feature\Api;

use App\Enums\ResumeStatus;
use App\Jobs\AnalyzeResumeJob;
use App\Models\Resume;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;
use Tests\Unit\Services\Storage\FakeAwsFailure;

/**
 * Feature tests for the resume upload/list/detail API
 * (App\Http\Controllers\Api\ResumeController / routes/api.php).
 *
 * Validates: Requirements 2.1, 2.4, 8.5
 */
class ResumeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('s3');

        // The local .env keeps RESUME_STORAGE_DISK=public for credential-free
        // dev; tests exercise the production default (Requirement 8.1).
        config(['filesystems.resume_disk' => 's3']);
    }

    /**
     * Builds a minimal-but-valid single-page PDF (hand-authored PDF
     * syntax, no external binary/library needed) containing the given
     * text, so `pdftotext` can actually extract something from it.
     */
    private function fakePdfFile(string $name = 'resume.pdf', string $text = 'John Doe Resume PHP Laravel Skills'): UploadedFile
    {
        $objects = [];
        $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>\nendobj\n";
        $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $content = "BT /F1 24 Tf 72 712 Td ({$text}) Tj ET";
        $objects[] = "5 0 obj\n<< /Length ".strlen($content)." >>\nstream\n{$content}\nendstream\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";

        $tmpPath = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($tmpPath, $pdf);

        return new UploadedFile($tmpPath, $name, 'application/pdf', null, true);
    }

    private function authHeader(User $user): array
    {
        $token = $user->createToken('test-token')->plainTextToken;

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_index_without_authorization_header_returns_401(): void
    {
        $this->getJson('/api/resumes')->assertStatus(401);
    }

    public function test_store_without_authorization_header_returns_401(): void
    {
        $this->postJson('/api/resumes', [])->assertStatus(401);
    }

    public function test_show_without_authorization_header_returns_401(): void
    {
        $resume = Resume::create([
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'resume.pdf',
            'file_path' => 'resumes/resume.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        $this->getJson("/api/resumes/{$resume->id}")->assertStatus(401);
    }

    public function test_destroy_without_authorization_header_returns_401(): void
    {
        $resume = Resume::create([
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'resume.pdf',
            'file_path' => 'resumes/resume.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        $this->deleteJson("/api/resumes/{$resume->id}")->assertStatus(401);
    }

    public function test_index_returns_only_the_authenticated_users_resumes_paginated(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Resume::create([
            'user_id' => $user->id,
            'original_filename' => 'mine.pdf',
            'file_path' => 'resumes/mine.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        Resume::create([
            'user_id' => $otherUser->id,
            'original_filename' => 'not-mine.pdf',
            'file_path' => 'resumes/not-mine.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson('/api/resumes');

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'per_page'])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_filename', 'mine.pdf');
    }

    public function test_store_with_a_valid_pdf_creates_a_resume_and_dispatches_analyze_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', [
                'resume' => $this->fakePdfFile(),
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'resume'])
            ->assertJsonPath('resume.user_id', $user->id)
            ->assertJsonPath('resume.original_filename', 'resume.pdf')
            ->assertJsonPath('resume.status', 'parsing')
            ->assertJsonPath('resume.storage_disk', 's3');

        $resumeId = $response->json('resume.id');
        $expectedKey = "users/{$user->id}/resumes/original/{$resumeId}.pdf";

        $this->assertDatabaseHas('resumes', [
            'id' => $resumeId,
            'user_id' => $user->id,
            'original_filename' => 'resume.pdf',
            'status' => 'parsing',
            'storage_disk' => 's3',
            'file_path' => $expectedKey,
        ]);

        // Stored on the s3 disk under the deterministic key, not the local
        // public disk (Requirements 8.1, 8.2).
        Storage::disk('s3')->assertExists($expectedKey);
        Storage::disk('public')->assertDirectoryEmpty('/');

        Queue::assertPushed(AnalyzeResumeJob::class, 1);
    }

    public function test_index_and_show_surface_the_status_and_failure_reason(): void
    {
        $user = User::factory()->create();
        $resume = Resume::create([
            'user_id' => $user->id,
            'original_filename' => 'mine.pdf',
            'file_path' => 'resumes/mine.pdf',
            'is_optimized' => false,
            'status' => ResumeStatus::Parsing,
        ]);

        $resume->markFailed('The resume analysis service returned an error (HTTP 500).');

        $this->withHeaders($this->authHeader($user))
            ->getJson('/api/resumes')
            ->assertStatus(200)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.status_error', 'The resume analysis service returned an error (HTTP 500).');

        $this->withHeaders($this->authHeader($user))
            ->getJson("/api/resumes/{$resume->id}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'failed')
            ->assertJsonPath('status_error', 'The resume analysis service returned an error (HTTP 500).');
    }

    public function test_store_marks_the_resume_failed_with_a_reason_when_storage_fails(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        // Force the underlying disk write to blow up so the controller takes
        // its failure path (Requirement 2.4).
        Storage::shouldReceive('disk')
            ->with('s3')
            ->andThrow(new \RuntimeException('s3 unreachable'));

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', [
                'resume' => $this->fakePdfFile(),
            ]);

        $response->assertStatus(500)
            ->assertJsonPath('resume.status', 'failed');

        $this->assertNotNull($response->json('resume.status_error'));

        Queue::assertNothingPushed();
    }

    /**
     * Requirement 8.5 on a path with no queue behind it: the write is retried
     * in place, so a bucket that refuses one PUT does not become an error the
     * user has to react to.
     *
     * A mock disk rather than the fake one, because a working filesystem cannot
     * be made to fail the first write and accept the second.
     */
    public function test_store_retries_a_transient_storage_failure_and_succeeds(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $attempts = 0;

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->times(2)->andReturnUsing(function () use (&$attempts) {
            $attempts++;

            // First attempt: the `false` the s3 driver returns with
            // `'throw' => false`. Second: the key it wrote.
            return $attempts === 1 ? false : 'stored';
        });
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', [
                'resume' => $this->fakePdfFile(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('resume.status', 'parsing');

        $this->assertSame(2, $attempts);

        $this->assertDatabaseHas('resumes', [
            'id' => $response->json('resume.id'),
            'status' => 'parsing',
            'file_path' => "users/{$user->id}/resumes/original/{$response->json('resume.id')}.pdf",
        ]);

        Queue::assertPushed(AnalyzeResumeJob::class, 1);
    }

    /**
     * The interactive path is capped lower than the queued one because a person
     * is waiting on the response — two attempts, not three.
     */
    public function test_store_bounds_its_retries_by_the_interactive_attempt_cap(): void
    {
        Queue::fake();

        config(['filesystems.upload_retry.interactive_attempts' => 2]);

        $user = User::factory()->create();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->times(2)->andReturn(false);
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', [
                'resume' => $this->fakePdfFile(),
            ])
            ->assertStatus(500)
            ->assertJsonPath('resume.status', 'failed');

        // Requirement 8.5's other half on this path: the parse step never
        // starts, and the row does not claim to hold a file.
        $this->assertDatabaseHas('resumes', [
            'user_id' => $user->id,
            'status' => 'failed',
            'file_path' => '',
        ]);

        Queue::assertNothingPushed();
    }

    /**
     * Bad credentials are not a transient condition, so the user is told
     * immediately instead of after a retry loop that cannot help.
     */
    public function test_store_does_not_retry_a_non_retryable_storage_failure(): void
    {
        Queue::fake();

        config(['filesystems.upload_retry.interactive_attempts' => 3]);

        $user = User::factory()->create();

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')
            ->once()
            ->andThrow(new FakeAwsFailure('access denied', statusCode: 403));
        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

        $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', [
                'resume' => $this->fakePdfFile(),
            ])
            ->assertStatus(500)
            ->assertJsonPath('resume.status', 'failed');

        Queue::assertNothingPushed();
    }

    public function test_store_honours_the_configured_resume_disk_for_local_dev(): void
    {
        Queue::fake();

        config(['filesystems.resume_disk' => 'public']);

        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', [
                'resume' => $this->fakePdfFile(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('resume.storage_disk', 'public');

        $key = "users/{$user->id}/resumes/original/{$response->json('resume.id')}.pdf";

        Storage::disk('public')->assertExists($key);
        Storage::disk('s3')->assertDirectoryEmpty('/');
    }

    public function test_store_without_a_file_returns_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['resume']);
    }

    public function test_store_rejects_a_non_pdf_file_with_422(): void
    {
        $user = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($user))
            ->postJson('/api/resumes', [
                'resume' => UploadedFile::fake()->create('resume.txt', 10, 'text/plain'),
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['resume']);
    }

    public function test_show_returns_a_resume_owned_by_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $resume = Resume::create([
            'user_id' => $user->id,
            'original_filename' => 'mine.pdf',
            'file_path' => 'resumes/mine.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->getJson("/api/resumes/{$resume->id}");

        $response->assertStatus(200)
            ->assertJsonPath('id', $resume->id)
            ->assertJsonPath('original_filename', 'mine.pdf');
    }

    public function test_show_returns_404_for_another_users_resume(): void
    {
        $owner = User::factory()->create();
        $resume = Resume::create([
            'user_id' => $owner->id,
            'original_filename' => 'owner.pdf',
            'file_path' => 'resumes/owner.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        $otherUser = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($otherUser))
            ->getJson("/api/resumes/{$resume->id}");

        $response->assertStatus(404);
    }

    public function test_destroy_deletes_a_resume_owned_by_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $resume = Resume::create([
            'user_id' => $user->id,
            'original_filename' => 'mine.pdf',
            'file_path' => 'resumes/mine.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        $response = $this->withHeaders($this->authHeader($user))
            ->deleteJson("/api/resumes/{$resume->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Resume deleted.');

        $this->assertDatabaseMissing('resumes', ['id' => $resume->id]);
    }

    public function test_destroy_returns_404_for_another_users_resume(): void
    {
        $owner = User::factory()->create();
        $resume = Resume::create([
            'user_id' => $owner->id,
            'original_filename' => 'owner.pdf',
            'file_path' => 'resumes/owner.pdf',
            'is_optimized' => false,
            'status' => 'uploaded',
        ]);

        $otherUser = User::factory()->create();

        $response = $this->withHeaders($this->authHeader($otherUser))
            ->deleteJson("/api/resumes/{$resume->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('resumes', ['id' => $resume->id]);
    }
}
