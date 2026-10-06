<?php

namespace App\Services\Apply;

use App\Models\JobListing;
use App\Models\User;
use App\Models\UserProfile;

/**
 * Everything an {@see ApplyAdapter} needs for one application attempt
 * (design.md §7, Requirement 9.1).
 *
 * Read-only on purpose: an adapter maps this onto a worker step script, it does
 * not get to mutate pipeline state. Persisting the outcome is the caller's job
 * (the `SubmitApplication` queued job, task 15.7), which keeps adapters pure
 * enough to test against fixtures.
 *
 * Document paths are S3 keys, not bytes. The worker downloads from a short-lived
 * signed URL because the apply request body is capped well below a PDF.
 */
final class ApplyContext
{
    /**
     * @param  JobListing  $job  The posting being applied to; `application_url` is the target.
     * @param  User  $user  Owner of the attempt, for caps and audit.
     * @param  UserProfile  $profile  Source of truth for every answer an adapter fills in.
     * @param  string  $tailoredResumePath  S3 key of the compiled resume PDF.
     * @param  string|null  $coverLetterPath  S3 key of the cover letter PDF, when one was produced.
     * @param  array<string, string>  $answers  Optional pre-resolved screening answers, keyed by question.
     */
    public function __construct(
        public readonly JobListing $job,
        public readonly User $user,
        public readonly UserProfile $profile,
        public readonly string $tailoredResumePath,
        public readonly ?string $coverLetterPath = null,
        public readonly array $answers = [],
    ) {}

    /** The URL the adapter drives. Empty when the posting carries no apply link. */
    public function applicationUrl(): string
    {
        return trim((string) ($this->job->application_url ?? ''));
    }

    public function hasCoverLetter(): bool
    {
        return is_string($this->coverLetterPath) && $this->coverLetterPath !== '';
    }

    /** A pre-resolved answer for `$question`, or null when nobody has answered it. */
    public function answer(string $question): ?string
    {
        $value = $this->answers[$question] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
