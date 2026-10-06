<?php

namespace App\Services\Storage;

use App\Enums\TailoredDocumentType;
use App\Models\TailoredDocument;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Builds the S3 keys for a tailored document's artefacts and puts the files
 * there (Requirements 8.1, 8.2).
 *
 * This is the tailoring-specific slice of storage, and nothing more. Task 13.1
 * introduces a general `S3StorageService` — deterministic key builder, signed
 * URL generation, and `deleteForOwner()` wired to `resumes`/`applications`
 * deletion events (Req 8.3, 8.4) — which will almost certainly absorb this
 * class. That is the reason it is deliberately small: no signed URLs, no
 * deletion, no `resumes` keys, no persistence of the returned key onto the
 * model. It builds keys and uploads bytes, so that the future merge is a move
 * rather than an untangling.
 *
 * ## Which id the key hangs off, and why it is not the application id
 *
 * Req 8.2's example key for a tailored artefact is
 * `users/{user_id}/applications/{application_id}/resume.pdf`, which presumes an
 * `applications` row exists at upload time. In this pipeline it frequently does
 * not: a `tailored_documents` row is created `pending`, the LaTeX render and the
 * upload happen, and only then is the document linked to an application (see
 * {@see TailoredDocument::application()}, nullable `application_id`, and
 * {@see TailoredDocumentType::applicationForeignKey()}). A `store_only`
 * recommendation may mean no application row is ever created at all.
 *
 * Keying on `application_id` would therefore force one of two bad outcomes:
 * upload to a placeholder key and copy the object once the application appears
 * (two writes, a window where the persisted path is wrong, and an orphan if the
 * copy fails), or delay upload until the application exists (which inverts the
 * pipeline order for no benefit).
 *
 * So the key hangs off the `tailored_documents` id, which exists before the
 * first byte is written and never changes:
 *
 *     users/{user_id}/tailored-documents/{document_id}/resume.pdf
 *     users/{user_id}/tailored-documents/{document_id}/resume.tex
 *     users/{user_id}/tailored-documents/{document_id}/cover-letter.pdf
 *
 * This satisfies what 8.2 actually asks for — deterministic, namespaced, and
 * locatable from the owning record without a lookup — while removing the reason
 * a key would ever need rewriting. The user prefix is kept identical to the
 * original-upload keys so a single per-user prefix still covers everything a
 * user owns, which is what makes `deleteForOwner()` and per-user lifecycle rules
 * possible later.
 *
 * ## Upload failure is raised, not returned
 *
 * `Storage::put()` returns `false` on failure rather than throwing (the `s3`
 * disk is configured with `'throw' => false`), and an ignored `false` here would
 * persist an `s3_path` pointing at nothing — the worst outcome available, since
 * the document would look usable until someone tried to download it. Req 8.5
 * requires a failed upload to be retried with backoff by the queue and the step
 * *not* to be marked complete, and an exception is precisely the signal a queued
 * job needs for both: the release-and-retry is automatic and the row never
 * reaches `rendered`. Callers that want to swallow it can catch it; callers that
 * forget get the safe behaviour by default.
 *
 * ## …but not before the write has been retried in place
 *
 * Raising is the right *final* answer and an expensive first one, because the
 * queue retry this class hands off to re-runs the whole tailoring job — model
 * call, fabrication inspection, LaTeX compile — to redo one PUT. So
 * {@see put()} runs the write through {@see StorageWriteRetrier} first, and only
 * a write that keeps failing becomes the exception described above. The two
 * layers are complementary and the reasoning for the split lives in that class.
 * Nothing about the failure *shape* changes: the retrier re-raises the driver's
 * own throwable and returns the driver's own `false`, so the checks below and
 * the messages they produce are exactly what they were.
 */
class TailoredDocumentStorage
{
    /**
     * The disk, named explicitly rather than read from config.
     *
     * Req 8.1 is specific that generated artefacts go to `s3` and not the local
     * `public` disk. The original-upload path in `ResumeController` reads
     * `filesystems.resume_disk` so local development without AWS credentials can
     * fall back, but that escape hatch exists for interactive uploads; a queue
     * worker silently writing tailored PDFs to a local disk that the auto-apply
     * step later cannot read is a failure mode worth not having. `Storage::disk()`
     * is resolved through the facade so `Storage::fake('s3')` substitutes it in
     * tests.
     */
    private const DISK = 's3';

    /** Filename stem per document kind, hyphenated as in the Req 8.2 examples. */
    private const BASE_NAMES = [
        TailoredDocumentType::Resume->value => 'resume',
        TailoredDocumentType::CoverLetter->value => 'cover-letter',
    ];

    private StorageWriteRetrier $retrier;

    /**
     * The retrier is optional so `new TailoredDocumentStorage` keeps working —
     * the jobs resolve this class from the container and get the real one
     * injected, while the existing tests and any script that constructs it by
     * hand get the same default behaviour without having to know it exists.
     */
    public function __construct(?StorageWriteRetrier $retrier = null)
    {
        $this->retrier = $retrier ?? new StorageWriteRetrier;
    }

    /**
     * Key for the rendered PDF of `$document`.
     *
     * Pure and side-effect free, so a caller can build the key, decide what to
     * persist, and upload in whatever order suits it.
     */
    public function pdfKey(TailoredDocument $document): string
    {
        return $this->key($document, 'pdf');
    }

    /**
     * Key for the `.tex` source of `$document`, stored beside its PDF.
     *
     * Kept because the source is what a corrective retry (Req 6.4) and any later
     * "why does this document say that" question need; it lands in the same
     * prefix so the pair is never separated.
     */
    public function texKey(TailoredDocument $document): string
    {
        return $this->key($document, 'tex');
    }

    /**
     * Upload the rendered PDF at `$localPath` and return the key it was written
     * to.
     *
     * @throws RuntimeException if the file is unreadable or the upload fails
     */
    public function uploadPdf(TailoredDocument $document, string $localPath): string
    {
        return $this->upload($this->pdfKey($document), $localPath);
    }

    /**
     * Upload the `.tex` source at `$localPath` and return the key it was written
     * to.
     *
     * @throws RuntimeException if the file is unreadable or the upload fails
     */
    public function uploadTex(TailoredDocument $document, string $localPath): string
    {
        return $this->upload($this->texKey($document), $localPath);
    }

    /**
     * Upload the in-memory `.tex` source and return its key.
     *
     * The render step already holds the source as a string
     * ({@see \App\Services\Latex\RenderedDocument}), and on a failed compile
     * there is no file on disk to point at — so writing it to a temporary file
     * purely to hand this class a path would be ceremony with an extra failure
     * mode attached.
     *
     * @throws RuntimeException if the upload fails
     */
    public function uploadTexSource(TailoredDocument $document, string $texSource): string
    {
        return $this->put($this->texKey($document), $texSource);
    }

    /**
     * `users/{user_id}/tailored-documents/{document_id}/{base}.{extension}` —
     * see the class docblock for why the document id and not the application id.
     *
     * The identifiers are read off a persisted row and cast to `integer` by the
     * model, so no path traversal is reachable through them; both are still
     * required to be present, because a key containing an empty segment would
     * collide with every other key missing the same segment and would be
     * indistinguishable from a valid one on the bucket.
     */
    private function key(TailoredDocument $document, string $extension): string
    {
        $userId = (int) $document->user_id;
        $documentId = (int) $document->getKey();

        if ($userId <= 0 || $documentId <= 0) {
            throw new RuntimeException(
                'Cannot build an S3 key for a tailored document without a persisted id and user id.'
            );
        }

        $base = self::BASE_NAMES[$document->type->value];

        return "users/{$userId}/tailored-documents/{$documentId}/{$base}.{$extension}";
    }

    /**
     * @throws RuntimeException
     */
    private function upload(string $key, string $localPath): string
    {
        // Checked before the disk call so "you gave me a path that isn't there"
        // is never reported as an S3 problem — the two need different fixes, and
        // a queue retry cannot help the former.
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new RuntimeException("Cannot upload [{$localPath}] to [{$key}]: the local file is missing or unreadable.");
        }

        $contents = @file_get_contents($localPath);

        if ($contents === false) {
            throw new RuntimeException("Cannot upload [{$localPath}] to [{$key}]: the local file could not be read.");
        }

        return $this->put($key, $contents);
    }

    /**
     * The single point where anything is written, so the `false` return has
     * exactly one place to be checked.
     *
     * A throwing driver is caught and rethrown as the same exception type as a
     * `false` return, because a caller distinguishing the two would be reacting
     * to a disk configuration detail (`'throw' => true|false`) rather than to
     * anything about its own work. The original is chained so the AWS-level
     * message survives into the failed-job record.
     *
     * The write goes through {@see StorageWriteRetrier} (Req 8.5), so by the
     * time either failure is visible here the write has already been retried in
     * place as far as configuration allows, and the exception that leaves this
     * method genuinely means "storage is not accepting this object right now" —
     * which is the only condition worth paying a queue retry for. The retrier is
     * given the whole `put()` call rather than being wrapped around the facade,
     * because `Storage::disk()` itself can throw on a misconfigured disk and
     * that failure needs the same treatment.
     *
     * @throws RuntimeException
     */
    private function put(string $key, string $contents): string
    {
        try {
            $stored = $this->retrier->write(
                self::DISK,
                $key,
                fn () => Storage::disk(self::DISK)->put($key, $contents),
            );
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Failed to store ['.$key.'] on the "'.self::DISK.'" disk: '.$e->getMessage(),
                0,
                $e
            );
        }

        if ($stored === false) {
            throw new RuntimeException('Failed to store ['.$key.'] on the "'.self::DISK.'" disk.');
        }

        return $key;
    }
}
