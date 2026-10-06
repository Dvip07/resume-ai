<?php

namespace Tests\Feature\Jobs;

use App\Enums\ResumeStatus;
use App\Jobs\AnalyzeResumeJob;
use App\Models\Resume;
use App\Models\User;
use App\Services\ModelRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Status-transition contract for the resume analysis job:
 * a resume that enters as `parsing` must always leave in a terminal state,
 * with a user-facing reason when it fails.
 *
 * Since task 7.7 the extraction goes through ModelRouterService, so the faked
 * endpoint here is OpenRouter's chat/completions, not the retired local
 * `ollama.py` sidecar.
 *
 * Validates: Requirements 2.4, 2.2
 */
class AnalyzeResumeJobTest extends TestCase
{
    use RefreshDatabase;

    private const LLM_URL = 'https://openrouter.ai/api/v1/chat/completions';

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic routing/credentials so the job's behaviour doesn't
        // depend on the developer's .env.
        config([
            'services.openrouter.api_key' => 'test-key',
            'services.openrouter.base_url' => 'https://openrouter.ai/api/v1',
            'services.openrouter.default_tier' => 'cheap',
            'services.openrouter.tiers.cheap' => ['vendor/cheap-1'],
            'services.openrouter.max_attempts_per_model' => 1,
            'services.openrouter.retry_delay_ms' => 0,
        ]);

        Http::preventStrayRequests();
    }

    private function parsingResume(): Resume
    {
        return Resume::create([
            'user_id' => User::factory()->create()->id,
            'original_filename' => 'resume.pdf',
            'file_path' => 'users/1/resumes/original/1.pdf',
            'storage_disk' => 's3',
            'is_optimized' => false,
            'status' => ResumeStatus::Parsing,
        ]);
    }

    /**
     * An OpenRouter chat/completions body whose content is the given payload.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function llmPayload(array $data): array
    {
        return $this->completionBody(json_encode($data));
    }

    /**
     * @return array<string, mixed>
     */
    private function completionBody(string $content): array
    {
        return [
            'model' => 'vendor/cheap-1',
            'choices' => [['message' => ['role' => 'assistant', 'content' => $content]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'cost' => 0.0001],
        ];
    }

    private function job(Resume $resume): AnalyzeResumeJob
    {
        return new AnalyzeResumeJob($resume, 'John Doe PHP Laravel');
    }

    private function runJob(Resume $resume): void
    {
        $this->job($resume)->handle(app(ModelRouterService::class));
    }

    public function test_successful_analysis_marks_the_resume_parsed_and_clears_any_error(): void
    {
        Http::fake([
            self::LLM_URL => Http::response($this->llmPayload([
                'skills' => ['primary' => ['PHP'], 'secondary' => ['SQL']],
                'keywords' => ['laravel', 'php'],
                'summary' => 'Backend engineer.',
                'suggestedJobRoles' => ['Backend Engineer'],
            ]), 200),
        ]);

        $resume = $this->parsingResume();
        $resume->update(['status_error' => 'stale error from a previous attempt']);

        $this->runJob($resume);

        $resume->refresh();

        $this->assertSame(ResumeStatus::Parsed, $resume->status);
        $this->assertNull($resume->status_error);
        $this->assertNotEmpty($resume->parsed_data);
    }

    public function test_llm_error_response_marks_the_resume_failed_with_a_reason(): void
    {
        Http::fake([
            self::LLM_URL => Http::response('model unavailable', 500),
        ]);

        $resume = $this->parsingResume();

        $this->runJob($resume);

        $resume->refresh();

        $this->assertSame(ResumeStatus::Failed, $resume->status);
        $this->assertNotNull($resume->status_error);
        $this->assertStringContainsString('500', $resume->status_error);
    }

    public function test_unparseable_llm_output_marks_the_resume_failed(): void
    {
        Http::fake([
            self::LLM_URL => Http::response($this->completionBody('I am not JSON at all.'), 200),
        ]);

        $resume = $this->parsingResume();

        $this->runJob($resume);

        $resume->refresh();

        $this->assertSame(ResumeStatus::Failed, $resume->status);
        $this->assertNotNull($resume->status_error);
    }

    public function test_structurally_empty_output_marks_the_resume_failed(): void
    {
        Http::fake([
            self::LLM_URL => Http::response($this->llmPayload([]), 200),
        ]);

        $resume = $this->parsingResume();

        $this->runJob($resume);

        $resume->refresh();

        $this->assertSame(ResumeStatus::Failed, $resume->status);
        $this->assertStringContainsString('no readable structured data', $resume->status_error);
    }

    public function test_an_exception_during_analysis_marks_the_resume_failed(): void
    {
        Http::fake(function () {
            throw new \RuntimeException('connection refused');
        });

        $resume = $this->parsingResume();

        $this->runJob($resume);

        $resume->refresh();

        $this->assertSame(ResumeStatus::Failed, $resume->status);
        $this->assertStringContainsString('connection refused', $resume->status_error);
    }

    public function test_failed_handler_marks_a_stuck_parsing_resume_failed(): void
    {
        $resume = $this->parsingResume();

        $this->job($resume)->failed(new \RuntimeException('worker timed out'));

        $resume->refresh();

        $this->assertSame(ResumeStatus::Failed, $resume->status);
        $this->assertStringContainsString('worker timed out', $resume->status_error);
    }

    public function test_failed_handler_does_not_overwrite_an_already_parsed_resume(): void
    {
        $resume = $this->parsingResume();
        $resume->markParsed();

        $this->job($resume)->failed(new \RuntimeException('late failure callback'));

        $resume->refresh();

        $this->assertSame(ResumeStatus::Parsed, $resume->status);
        $this->assertNull($resume->status_error);
    }

    public function test_failed_handler_tolerates_a_deleted_resume(): void
    {
        $resume = $this->parsingResume();
        $job = $this->job($resume);

        $resume->delete();

        $job->failed(new \RuntimeException('worker timed out'));

        $this->assertDatabaseMissing('resumes', ['id' => $resume->id]);
    }
}
