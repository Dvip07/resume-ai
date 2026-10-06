<?php

namespace App\Services\Apply\Adapters;

/**
 * The tailored documents an apply run has to attach, resolved to signed URLs.
 *
 * Signing happens once, in {@see AbstractApplyAdapter::apply()}, before any
 * script is compiled: a URL that cannot be signed is a retryable failure that
 * should cost nothing, and an adapter's `compile()` should not have to think
 * about storage. The filename is carried alongside because the form shows it to
 * a human reviewer — "resume.pdf" rather than a signed-URL query string.
 */
final class ApplyDocuments
{
    public function __construct(
        public readonly string $resumeUrl,
        public readonly string $resumeFilename,
        public readonly ?string $coverLetterUrl = null,
        public readonly ?string $coverLetterFilename = null,
    ) {}

    public function hasCoverLetter(): bool
    {
        return is_string($this->coverLetterUrl) && $this->coverLetterUrl !== '';
    }
}
