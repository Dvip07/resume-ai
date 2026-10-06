<?php

namespace App\Support;

use App\Enums\PipelineStage;
use App\Models\Application;
use App\Models\JobScore;
use App\Models\TailoredDocument;
use App\Services\Storage\S3StorageService;
use RuntimeException;

/**
 * Shapes an `applications` row into the payload the frontend Applications
 * dashboard reads (Requirements 10.1, 10.2, 10.3).
 *
 * ## Why a presenter and not a controller method
 *
 * The dashboard needs three things no single table holds: the listing's
 * `pipeline_stage` and JD (`job_listings`), the star rating and its rationale
 * (`job_scores`, latest attempt), and download links for the submitted
 * documents (`tailored_documents`, signed on demand per Requirement 8.3). The
 * list and detail endpoints need overlapping-but-different slices of that, so
 * the assembly lives in one place and each endpoint picks its depth
 * ({@see summary()} vs {@see detail()}) rather than two controller methods
 * growing the same joins separately.
 *
 * ## Additive, never subtractive
 *
 * Every presented payload starts from `$application->toArray()`, so each raw
 * column the API already returned (`status`, `response_status`, `metadata`, …)
 * stays exactly where it was and the nested keys are added alongside. Nothing
 * that already consumed this API can break by upgrading to it.
 *
 * ## Signed URLs are best-effort
 *
 * {@see S3StorageService::temporaryUrl()} throws for a document that was never
 * rendered, and a read endpoint that 500s because one PDF is missing is worse
 * than one that reports the document as undownloadable. A failure therefore
 * becomes `download_url: null` plus a `download_error` string the UI can show
 * next to the document, which is the same "never a silent empty state" rule the
 * rest of the pipeline follows.
 */
class ApplicationPresenter
{
    public function __construct(private readonly S3StorageService $storage)
    {
    }

    /**
     * Relations every presented payload reads. Passed to `with()`/`load()` by
     * the controller so a 15-row page costs a fixed number of queries.
     *
     * @return array<int, string>
     */
    public static function relations(): array
    {
        return ['jobListing', 'tailoredResume', 'tailoredCoverLetter'];
    }

    /**
     * List-row payload: enough to render the row and its badges, and nothing
     * that costs an S3 signature or ships a full JD down the wire.
     *
     * @param  array<int, JobScore|null>  $scoresByListing  keyed by job_listing_id
     * @return array<string, mixed>
     */
    public function summary(Application $application, array $scoresByListing = []): array
    {
        $listing = $application->jobListing;
        $score = $scoresByListing[$application->job_listing_id] ?? null;

        return $application->toArray() + [
            'job_listing' => $listing === null ? null : [
                'id' => $listing->id,
                'title' => $listing->title,
                'company' => $listing->company,
                'location' => $listing->location,
                'application_url' => $listing->application_url,
                'source_key' => $listing->source_key,
                'posted_at' => optional($listing->posted_at)->toIso8601String(),
                'pipeline_stage' => $this->stageValue($listing->pipeline_stage),
            ],
            'score' => $score === null ? null : [
                'stars' => $score->stars,
                'recommended_action' => $score->recommended_action?->value,
                'attempt_number' => $score->attempt_number,
            ],
            'needs_review' => $this->needsReview($application),
            'review' => $this->review($application),
        ];
    }

    /**
     * Detail payload: the summary plus the full JD, the score rationale and fit
     * analysis, signed document links, the automation log, and the manual
     * status history (Requirement 10.2).
     *
     * @return array<string, mixed>
     */
    public function detail(Application $application): array
    {
        $listing = $application->jobListing;
        $score = $listing === null
            ? null
            : JobScore::latestAttempt((int) $listing->id, (int) $application->user_id);

        $payload = $this->summary(
            $application,
            $score === null ? [] : [$application->job_listing_id => $score],
        );

        if ($listing !== null && is_array($payload['job_listing'])) {
            $payload['job_listing'] += [
                'description' => $listing->description,
                'parsed_skills' => $listing->parsed_skills,
                'salary_min' => $listing->salary_min,
                'salary_max' => $listing->salary_max,
                'currency' => $listing->currency,
            ];
        }

        if ($score !== null && is_array($payload['score'])) {
            $payload['score'] += [
                'rationale' => $score->rationale,
                'fit_analysis' => $score->fit_analysis,
                'created_at' => optional($score->created_at)->toIso8601String(),
            ];
        }

        $payload['documents'] = array_values(array_filter([
            $this->document($application->tailoredResume),
            $this->document($application->tailoredCoverLetter),
        ]));

        $payload['manual_status_history'] = $this->manualStatusHistory($application);

        return $payload;
    }

    /**
     * One document entry with a freshly signed link.
     *
     * @return array<string, mixed>|null
     */
    private function document(?TailoredDocument $document): ?array
    {
        if ($document === null) {
            return null;
        }

        $downloadUrl = null;
        $downloadError = null;

        try {
            $downloadUrl = $this->storage->tailoredDocumentUrl($document);
        } catch (RuntimeException $e) {
            // See the class docblock: a missing object is reported, not raised.
            $downloadError = $e->getMessage();
        }

        return [
            'id' => $document->id,
            'type' => $document->type->value,
            'status' => $document->status->value,
            'template_key' => $document->template_key,
            'generation_model' => $document->generation_model,
            'fabrication_flags' => $document->fabrication_flags,
            'created_at' => optional($document->created_at)->toIso8601String(),
            'download_url' => $downloadUrl,
            'download_error' => $downloadError,
        ];
    }

    /** Is this application waiting on a person? (Requirement 10.3) */
    private function needsReview(Application $application): bool
    {
        return $this->stageValue($application->jobListing?->pipeline_stage)
            === PipelineStage::NeedsReview->value;
    }

    /**
     * The call-to-action for a `needs_review` row: what the user has to do, and
     * the evidence behind it (Requirement 10.3).
     *
     * ## Why this is derived rather than read from a column
     *
     * The stages that flag a row (`ScoreJobListing`, `TailorResume`,
     * `EnrichJobDescription`) currently *log* their reason — `job_listings` has
     * no review-reason column, as `ScoreJobListing`'s docblock notes. Rather
     * than ship a dashboard whose call-to-action reads "something needs
     * attention", the reason is reconstructed from the evidence that *is*
     * persisted, in order of how specific it is:
     *
     * 1. unanswered application questions in `automation_log` (Requirement 9.4)
     * 2. fabrication flags on a submitted document (Requirement 6.6)
     * 3. an adapter failure reason in `automation_log`
     * 4. a failed/missing tailored document
     *
     * and falls back to an honest "the reason wasn't recorded" when none of
     * those is present, which is the case for a scoring/enrichment dead end
     * until a `review_reason` column exists. That column is the right fix and
     * is called out in the task report rather than smuggled in here.
     *
     * @return array<string, mixed>|null
     */
    private function review(Application $application): ?array
    {
        if (! $this->needsReview($application)) {
            return null;
        }

        $log = is_array($application->automation_log) ? $application->automation_log : [];

        $questions = $this->stringList($log['unanswered_questions'] ?? $log['unansweredQuestions'] ?? null);

        if ($questions !== []) {
            return [
                'kind' => 'unanswered_questions',
                'headline' => 'Answer these application questions to continue',
                'detail' => 'The apply automation paused rather than guessing at answers.',
                'questions' => $questions,
            ];
        }

        $flagged = $this->flaggedDocuments($application);

        if ($flagged !== []) {
            return [
                'kind' => 'possible_fabrication',
                'headline' => 'Review possible resume fabrication',
                'detail' => 'Details in the generated documents could not be traced back to your profile, so nothing was submitted.',
                'documents' => $flagged,
            ];
        }

        $failureReason = $this->text($log['failure_reason'] ?? $log['failureReason'] ?? null);

        // A wall gets its own call to action (Requirement 9.6). It reads
        // differently from a failure on purpose: nothing is wrong with the
        // posting or the documents, the employer's form asked for a person, and
        // "retry" is not on the table — so the CTA sends the user to the
        // posting rather than back through the automation. The adapter records
        // the wall under `metadata.blocked` (see
        // `AbstractApplyAdapter::blockedDetection()`).
        $blockedKind = $this->blockedKind($log);

        if ($blockedKind !== null) {
            return [
                'kind' => 'blocked_by_ats',
                'headline' => match ($blockedKind) {
                    'captcha' => 'This application needs a person to clear a bot check',
                    'auth_wall' => 'This application is behind a sign-in wall',
                    'already_applied' => 'The employer already has an application from you',
                    default => 'Finish this application manually',
                },
                'detail' => $failureReason
                    ?? 'The apply automation stopped at a wall it will not try to work around, so the application has '
                        .'to be submitted manually using the posting link.',
                'blocked_by' => $blockedKind,
            ];
        }

        if ($failureReason !== null) {
            return [
                'kind' => 'automation_failure',
                'headline' => 'The apply automation stopped and needs you',
                'detail' => $failureReason,
            ];
        }

        if ($this->hasUnusableDocuments($application)) {
            return [
                'kind' => 'tailoring_incomplete',
                'headline' => 'Tailoring did not produce a usable document',
                'detail' => 'Re-run tailoring, or apply manually using the posting link.',
            ];
        }

        return [
            'kind' => 'unspecified',
            'headline' => 'This application is paused for review',
            'detail' => 'The pipeline stopped here and did not record a reason. Check the automation log below, or apply manually using the posting link.',
        ];
    }

    /**
     * The kind of wall an apply run stopped against (`captcha`, `auth_wall`,
     * `already_applied`), or null when it did not stop against one.
     *
     * Read from either the adapter metadata nested under `metadata`, or the
     * same key at the top of the log, because the log is written from
     * `ApplyResult::toArray()` and a caller may flatten it.
     *
     * @param  array<string, mixed>  $log
     */
    private function blockedKind(array $log): ?string
    {
        $metadata = is_array($log['metadata'] ?? null) ? $log['metadata'] : [];
        $blocked = $metadata['blocked'] ?? $log['blocked'] ?? null;

        if (! is_array($blocked)) {
            return null;
        }

        return $this->text($blocked['kind'] ?? null);
    }

    /**
     * Types of document carrying a fabrication finding, e.g. `['resume']`.
     *
     * @return array<int, string>
     */
    private function flaggedDocuments(Application $application): array
    {
        $flagged = [];

        foreach ([$application->tailoredResume, $application->tailoredCoverLetter] as $document) {
            if ($document === null) {
                continue;
            }

            $flags = $document->fabrication_flags;

            // A report is stored whether or not it found anything, so presence
            // alone is not a finding — `findings` is.
            $findings = is_array($flags) ? ($flags['findings'] ?? null) : null;

            if (is_array($findings) && $findings !== []) {
                $flagged[] = $document->type->value;
            }
        }

        return $flagged;
    }

    /** True when a linked document exists but is not safe to submit. */
    private function hasUnusableDocuments(Application $application): bool
    {
        foreach ([$application->tailoredResume, $application->tailoredCoverLetter] as $document) {
            if ($document !== null && ! $document->isUsable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Manual status updates the user recorded, newest last — written by
     * `ApplicationController::update()` under `metadata.manual_status_history`
     * (Requirement 10.4). Pulled to the top level so the UI does not have to
     * know it lives inside the free-form metadata blob.
     *
     * @return array<int, array<string, mixed>>
     */
    private function manualStatusHistory(Application $application): array
    {
        $metadata = $application->metadata ?? [];
        $history = is_array($metadata) ? ($metadata['manual_status_history'] ?? []) : [];

        if (! is_array($history) || ($history !== [] && ! array_is_list($history))) {
            return [];
        }

        return array_values(array_filter($history, 'is_array'));
    }

    /** @return array<int, string> */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $single = $this->text($value);

            return $single === null ? [] : [$single];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $entry) {
            // Adapters may record a question as a string or as a
            // {question, field} map; both read as one question here.
            $text = is_array($entry)
                ? $this->text($entry['question'] ?? $entry['label'] ?? $entry['field'] ?? null)
                : $this->text($entry);

            if ($text !== null) {
                $out[] = $text;
            }
        }

        return $out;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** `pipeline_stage` is cast to an enum, but legacy rows may hold a string. */
    private function stageValue(mixed $stage): ?string
    {
        return $stage instanceof PipelineStage ? $stage->value : $this->text($stage);
    }
}
