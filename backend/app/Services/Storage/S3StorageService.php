<?php

namespace App\Services\Storage;

use App\Enums\TailoredDocumentType;
use App\Models\Application;
use App\Models\Resume;
use App\Models\TailoredDocument;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The deterministic key builder and signed-URL generator for every user
 * artefact the platform stores (Requirements 8.2, 8.3, 8.4).
 *
 * Three jobs, and deliberately no fourth — it does not upload anything (see
 * {@see TailoredDocumentStorage} and `ResumeController` for the write paths):
 *
 * 1. Given an owning record, say where its object lives. Keys are pure
 *    functions of ids that already exist on a persisted row, so any part of the
 *    system — a controller, a queue worker, a support script, a bucket
 *    lifecycle rule — can locate an object without a database lookup, which is
 *    the whole point of Req 8.2.
 * 2. Given a key, mint a short-lived signed URL for the frontend. Req 8.3 is
 *    explicit that records hold a *path*, never a public permanent URL, and
 *    that the URL is generated on demand. This class is the only place that
 *    generation happens.
 *
 * 3. Given an owning record, delete the objects *that record* owns
 *    ({@see deleteForOwner()}, Req 8.4). The blast radius of that method is the
 *    single most consequential decision in this class and is argued out in its
 *    own docblock below.
 *
 * ## Relationship to {@see TailoredDocumentStorage} — decided, not deferred
 *
 * `TailoredDocumentStorage` predates this class, its docblock anticipates being
 * absorbed by it, and it already owns the canonical tailored-document key shape.
 * The decision for the next slice is: **`TailoredDocumentStorage` keeps its
 * public API and delegates its key building here; it is not deleted, and its
 * callers are not touched.**
 *
 * The reasons, in order of weight:
 *
 * - Its `uploadPdf()` / `uploadTex()` / `uploadTexSource()` methods are not
 *   generic storage. They encode a tailoring-specific contract — upload failure
 *   is raised rather than returned, so a queued job releases and retries and the
 *   row never reaches `rendered` (Req 8.5). Folding that into a general service
 *   would either impose it on callers that do not want it (the interactive
 *   resume upload in `ResumeController` deliberately converts a failure into a
 *   500 and a `failed` row instead) or require a flag to switch between the two,
 *   which is worse than two classes.
 * - The key shape is the only genuine duplication, and delegation removes it
 *   without moving a single call site. After the next slice there is exactly one
 *   definition of `users/{user_id}/tailored-documents/{document_id}/…` — this
 *   one — and `TailoredDocumentStorage` becomes a thin tailoring-flavoured
 *   facade over it.
 * - `TailorResume` and its tests are wired to `TailoredDocumentStorage` today.
 *   Absorption would churn them for no behavioural gain, in a task whose
 *   remaining work (deletion on model events) is the part that carries real
 *   risk.
 *
 * Until that delegation lands, the tailored-document key shape exists in both
 * classes and the two must stay byte-identical. They are, and
 * `tailoredDocumentKey()` below is a transcription of
 * `TailoredDocumentStorage::key()` rather than a reinterpretation of it.
 *
 * ## Why the tailored key hangs off the document id
 *
 * Req 8.2 offers `users/{user_id}/applications/{application_id}/resume.pdf` as
 * an example, and this class does not use it. The reasoning is
 * `TailoredDocumentStorage`'s and is preserved verbatim in intent: an
 * `applications` row often does not exist when the object is written. A
 * `tailored_documents` row is created `pending`, rendered, and uploaded, and only
 * then linked to an application — `application_id` is nullable, and a
 * `store_only` recommendation may mean no application row is ever created.
 * Keying on it would force either a placeholder key plus a copy once the
 * application appears (two writes, a window where the persisted path is wrong,
 * an orphan if the copy fails) or a delayed upload that inverts the pipeline
 * order for nothing.
 *
 * The document id exists before the first byte is written and never changes, so
 * the key never needs rewriting:
 *
 *     users/{user_id}/resumes/original/{resume_id}.pdf
 *     users/{user_id}/tailored-documents/{document_id}/resume.pdf
 *     users/{user_id}/tailored-documents/{document_id}/resume.tex
 *     users/{user_id}/tailored-documents/{document_id}/cover-letter.pdf
 *
 * Every shape shares the `users/{user_id}/` prefix. That is not cosmetic: it is
 * what makes a single prefix cover everything one user owns, which is what
 * {@see deleteForOwner()}'s user branch and any per-user bucket lifecycle rule
 * rely on.
 *
 * ## Signing on drivers that cannot sign
 *
 * `temporaryUrl()` throws `RuntimeException` on drivers with no notion of
 * signing — which in this codebase means a real `local`/`public` disk, the
 * documented local-development fallback for `filesystems.resume_disk`
 * (`RESUME_STORAGE_DISK=public`). Three options were available: throw, return
 * null, or fall back to a plain `url()`.
 *
 * This class **falls back to `url()` and logs a warning**, and throws only when
 * neither a signed nor a plain URL can be produced. Throwing outright would
 * make local development without AWS credentials impossible for every screen
 * that shows a document — the exact escape hatch `resume_disk` exists to
 * provide — and would turn a configuration choice into a 500 on a read path.
 * Returning null pushes an `if` into every caller and, being easy to ignore,
 * eventually renders a broken link with no signal anywhere.
 *
 * The fallback's real cost is that a misconfigured *production* deploy would
 * quietly serve non-expiring URLs, which is a Req 8.3 violation. The warning log
 * is what makes that loud rather than silent, and it names the disk and driver
 * so the fix is obvious. The failure mode of the alternative — an outage on a
 * correctly-if-unusually configured dev box — is the more likely one and the
 * less recoverable one for a developer.
 *
 * Tests are unaffected either way: `Storage::fake()` registers a temporary-URL
 * callback, so signing works under it and the fallback branch is not reached by
 * accident. Reaching it requires a genuinely non-signing disk, which is what a
 * test of the fallback should set up explicitly.
 */
class S3StorageService
{
    /**
     * Default disk for artefacts.
     *
     * Read from `filesystems.resume_disk` rather than hardcoded to `s3`,
     * because this class also *reads* objects (URL generation) and must look
     * where they were actually written. `ResumeController` writes originals to
     * the configured disk, so a service that always assumed `s3` would mint
     * URLs for objects that are not there whenever the fallback disk is in use.
     * Callers holding a record with its own `storage_disk` (see
     * {@see Resume::$storage_disk}) should pass it explicitly and not rely on
     * this default.
     */
    private function defaultDisk(): string
    {
        $disk = config('filesystems.resume_disk', 's3');

        return is_string($disk) && $disk !== '' ? $disk : 's3';
    }

    /**
     * Tailored artefacts are written to `s3` unconditionally by
     * {@see TailoredDocumentStorage::DISK}, so they are deleted from `s3`
     * unconditionally too. Routing this through `defaultDisk()` would look for
     * them on `public` on a machine using the original-upload fallback, find
     * nothing, and report a clean delete.
     */
    private const TAILORED_DISK = 's3';

    /** Filename stem per document kind, hyphenated as in the Req 8.2 examples. */
    private const TAILORED_BASE_NAMES = [
        TailoredDocumentType::Resume->value => 'resume',
        TailoredDocumentType::CoverLetter->value => 'cover-letter',
    ];

    /**
     * `users/{user_id}/resumes/original/{resume_id}.pdf` — the original upload
     * (Req 8.2).
     *
     * Duplicates the private helper in `ResumeController` for now; that
     * controller is a caller to be migrated, not a second source of truth, and
     * the shape is fixed by the objects already in the bucket.
     */
    public function originalResumeKey(Resume $resume): string
    {
        $userId = (int) $resume->user_id;
        $resumeId = (int) $resume->getKey();

        $this->assertIdentifiers([
            'user id' => $userId,
            'resume id' => $resumeId,
        ], 'resume');

        return "users/{$userId}/resumes/original/{$resumeId}.pdf";
    }

    /**
     * `users/{user_id}/tailored-documents/{document_id}/{resume|cover-letter}.{extension}`
     * — byte-identical to what {@see TailoredDocumentStorage} already produces,
     * because objects are keyed that way today and changing it would orphan
     * every one of them.
     *
     * `$extension` is whitelisted rather than interpolated as given: it is the
     * only segment of the key not derived from a cast integer, so it is the only
     * one through which a caller could put something unexpected — a traversal
     * segment, a query string, an empty string — into a key that then gets
     * persisted.
     */
    public function tailoredDocumentKey(TailoredDocument $document, string $extension = 'pdf'): string
    {
        $userId = (int) $document->user_id;
        $documentId = (int) $document->getKey();

        $this->assertIdentifiers([
            'user id' => $userId,
            'document id' => $documentId,
        ], 'tailored document');

        if (! in_array($extension, ['pdf', 'tex'], true)) {
            throw new RuntimeException(
                "Cannot build an S3 key for tailored document [{$documentId}]: unsupported extension [{$extension}]."
            );
        }

        $base = self::TAILORED_BASE_NAMES[$document->type->value]
            ?? throw new RuntimeException(
                "Cannot build an S3 key for tailored document [{$documentId}]: unknown document type."
            );

        return "users/{$userId}/tailored-documents/{$documentId}/{$base}.{$extension}";
    }

    /**
     * The prefix covering **everything** one user owns.
     *
     * Read the emphasis as a warning. This prefix is the right unit exactly
     * once — deleting the user themselves — and is the wrong unit for every
     * other caller: using it while deleting a single resume or a single tailored
     * document would take out every other document that user has. It is public
     * because {@see deleteForOwner()} needs it and because per-user bucket
     * lifecycle rules are written against it, not because it is a general-purpose
     * helper.
     */
    public function userPrefix(int $userId): string
    {
        $this->assertIdentifiers(['user id' => $userId], 'user');

        return "users/{$userId}/";
    }

    /**
     * Delete the S3 objects owned by `$owner` (Req 8.4), returning the keys the
     * disk reported deleting.
     *
     * ## Blast radius, per owner type
     *
     * The rule is: **delete named keys, not directories.** A key is derived from
     * the owning row's own ids or read off its own columns, so the worst a bug
     * in the caller can do is delete one record's objects. `deleteDirectory()`
     * has no such bound, and there is exactly one owner for which an unbounded
     * prefix *is* the correct scope.
     *
     * - {@see Resume} — deletes `resumes.file_path` (the path the upload
     *   actually persisted, which is authoritative) *and* the deterministic
     *   {@see originalResumeKey()} for the same row. Both are that resume's own
     *   keys, so the pair is still record-scoped; the second catches the one
     *   real orphan case, an upload that succeeded and then failed to persist
     *   its path. Deleting the `users/{id}/resumes/original/` prefix would have
     *   caught it too and would have taken every *other* resume of that user
     *   with it, so it is not done.
     * - {@see TailoredDocument} — deletes `s3_path` and `tex_source_s3_path`
     *   plus the rebuilt pdf/tex keys for the same document id, for the same
     *   reason. Note that even the per-document prefix
     *   `users/{u}/tailored-documents/{id}/` is tightly bounded here (it holds
     *   nothing but that document's two artefacts) — it is still not used,
     *   because the four named keys already cover everything that prefix can
     *   contain, and a directory call would survive a future decision to put
     *   something shared under that prefix in a way that named keys would not.
     * - {@see User} — the one whole-prefix delete, and it is supported. Both
     *   `resumes` and `tailored_documents` cascade from `users` at the database
     *   level, so deleting a user destroys every owning row *without* Eloquent
     *   ever loading them (see {@see \App\Observers\UserObserver}); there is
     *   consequently no set of rows left to read named keys off, and per-row
     *   deletion is simply not available. The alternative to the prefix delete
     *   is orphaning every object the user ever had. The prefix is
     *   `users/{user_id}/`, which by construction contains that user's objects
     *   and no one else's — that property is the entire reason every key shape
     *   above starts with it.
     * - {@see Application} — deletes **nothing**, deliberately, and this is not
     *   an omission. Req 8.4 names `applications`, but in this schema an
     *   application owns no object of its own: the PDF belongs to the
     *   `tailored_documents` row, and `tailored_documents.application_id` is
     *   `nullOnDelete`, so that row *survives* its application. Deleting the
     *   objects here would leave a live, `rendered` document row pointing at a
     *   missing PDF — a worse outcome than the storage cost 8.4 is trying to
     *   avoid. The objects are removed when the document row itself goes.
     *
     * ## Failures are logged, not raised
     *
     * Every delete is individually guarded and a failure is logged and stepped
     * over, because the callers are model-deletion hooks and a throw there would
     * either abort a deletion the user asked for or, worse, half-abort it. What
     * the operator gets in exchange is a `storage.delete_failed` warning naming
     * the disk and key; what the operator has to *do* about it is reap those
     * keys, since nothing will retry them — the row is gone and with it the only
     * record that the object existed. That is the argument for keeping a bucket
     * lifecycle rule on `users/` as a backstop rather than treating this method
     * as the sole guarantee.
     *
     * @return list<string> keys deleted, for logging and for tests
     */
    public function deleteForOwner(object $owner): array
    {
        return match (true) {
            $owner instanceof Resume => $this->deleteKeys($this->resumeKeys($owner), $this->resumeDisk($owner)),
            $owner instanceof TailoredDocument => $this->deleteKeys($this->tailoredDocumentKeys($owner), self::TAILORED_DISK),
            $owner instanceof User => $this->deleteUserPrefix($owner),
            // See the docblock: an application owns no objects of its own.
            $owner instanceof Application => [],
            default => throw new RuntimeException(
                'Cannot delete storage for ['.$owner::class.']: no ownership rule is defined for it.'
            ),
        };
    }

    /**
     * The persisted path first, then the rebuilt key — both belonging to this
     * one resume. Duplicates are collapsed, which is the normal case: a healthy
     * row's `file_path` *is* the deterministic key.
     *
     * @return list<string>
     */
    private function resumeKeys(Resume $resume): array
    {
        $keys = [];

        $persisted = is_string($resume->file_path) ? trim($resume->file_path) : '';

        if ($persisted !== '') {
            $keys[] = $persisted;
        }

        // Guarded rather than assumed: a resume being deleted mid-upload may have
        // no id-bearing key to build, and that is not a reason to abandon the
        // persisted path we already collected.
        try {
            $keys[] = $this->originalResumeKey($resume);
        } catch (RuntimeException) {
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return list<string>
     */
    private function tailoredDocumentKeys(TailoredDocument $document): array
    {
        $keys = [];

        foreach ([$document->s3_path, $document->tex_source_s3_path] as $persisted) {
            $persisted = is_string($persisted) ? trim($persisted) : '';

            if ($persisted !== '') {
                $keys[] = $persisted;
            }
        }

        foreach (['pdf', 'tex'] as $extension) {
            try {
                $keys[] = $this->tailoredDocumentKey($document, $extension);
            } catch (RuntimeException) {
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Which disk a resume's original lives on.
     *
     * `resumes.storage_disk` records where the upload actually went, so it wins
     * over the current configuration: a row written while `RESUME_STORAGE_DISK`
     * was `public` must be deleted from `public`, however the app is configured
     * today.
     */
    private function resumeDisk(Resume $resume): string
    {
        return is_string($resume->storage_disk) && $resume->storage_disk !== ''
            ? $resume->storage_disk
            : $this->defaultDisk();
    }

    /**
     * Wipe `users/{id}/` on every disk artefacts may have been written to.
     *
     * Two disks, not one: tailored documents always go to `s3`
     * ({@see TailoredDocumentStorage}), while originals go to the configured
     * `filesystems.resume_disk`, which on a developer machine is `public`. A
     * single-disk delete would silently leave one half behind on exactly the
     * setup where someone would look for it. `array_unique` makes the usual
     * production case (both are `s3`) a single call.
     *
     * @return list<string>
     */
    private function deleteUserPrefix(User $user): array
    {
        $userId = (int) $user->getKey();

        if ($userId <= 0) {
            return [];
        }

        $prefix = $this->userPrefix($userId);
        $deleted = [];

        foreach (array_unique([self::TAILORED_DISK, $this->defaultDisk()]) as $disk) {
            try {
                if (Storage::disk($disk)->deleteDirectory($prefix)) {
                    $deleted[] = $prefix;
                }
            } catch (\Throwable $e) {
                $this->logDeleteFailure($disk, $prefix, $e->getMessage());
            }
        }

        return array_values(array_unique($deleted));
    }

    /**
     * Delete each key independently, so one failure cannot strand the others.
     *
     * A `false` return is treated as "there was nothing there", not as an error:
     * on both S3 and the local driver a delete of a missing object is a success,
     * and the cases that do return `false` are indistinguishable from it without
     * a preceding `exists()` call — an extra round trip per key that would change
     * nothing about what this method does next. Genuine driver failures throw,
     * and those are what the warning log is for.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function deleteKeys(array $keys, string $disk): array
    {
        $deleted = [];

        foreach ($keys as $key) {
            try {
                if (Storage::disk($disk)->delete($key)) {
                    $deleted[] = $key;
                }
            } catch (\Throwable $e) {
                $this->logDeleteFailure($disk, $key, $e->getMessage());
            }
        }

        return $deleted;
    }

    /**
     * One message, one shape, so an operator can grep for the orphans this class
     * knowingly leaves behind.
     */
    private function logDeleteFailure(string $disk, string $key, string $reason): void
    {
        Log::warning('storage.delete_failed', [
            'disk' => $disk,
            'key' => $key,
            'reason' => $reason,
            'consequence' => 'the object is orphaned; nothing will retry this delete',
        ]);
    }

    /**
     * A temporary signed URL for `$key`, valid for the configured TTL
     * (Req 8.3).
     *
     * `$disk` defaults to the configured artefact disk; pass the owning
     * record's `storage_disk` when you have it. `$ttlMinutes` overrides
     * `filesystems.signed_url_ttl_minutes` for the rare caller that needs a
     * different window (an emailed link, say) — the default covers everything
     * else, and the config is the only place the number is written down.
     *
     * Falls back to an unsigned `url()` on a driver that cannot sign; see the
     * class docblock for why, and expect a warning in the log when it happens.
     *
     * @throws RuntimeException if the disk can produce neither a signed nor a
     *                          plain URL for the key
     */
    public function temporaryUrl(string $key, ?string $disk = null, ?int $ttlMinutes = null): string
    {
        $key = trim($key);

        if ($key === '') {
            throw new RuntimeException('Cannot generate a signed URL for an empty object key.');
        }

        $disk ??= $this->defaultDisk();
        $filesystem = Storage::disk($disk);

        try {
            return $filesystem->temporaryUrl($key, now()->addMinutes($this->ttlMinutes($ttlMinutes)));
        } catch (\Throwable $signingFailure) {
            // Deliberately not narrowed to a message match: every reason a disk
            // cannot sign leads to the same decision here, and matching on
            // Laravel's wording would break silently on a framework upgrade —
            // silently, in the direction of throwing on a read path.
            try {
                $url = $filesystem->url($key);
            } catch (\Throwable $urlFailure) {
                throw new RuntimeException(
                    'Cannot generate a URL for ['.$key.'] on the "'.$disk.'" disk: the driver supports neither '
                    .'temporary nor public URLs. '.$signingFailure->getMessage(),
                    0,
                    $urlFailure
                );
            }

            Log::warning('Signed URL unavailable; served an unsigned URL instead.', [
                'disk' => $disk,
                'key' => $key,
                'reason' => $signingFailure->getMessage(),
            ]);

            return $url;
        }
    }

    /**
     * Signed URL for a resume's original upload, on the disk it was written to.
     *
     * The convenience matters more than it looks: `resumes.storage_disk`
     * records where the object actually went, and a caller that forgets to pass
     * it gets a URL for the wrong bucket on any machine using the fallback
     * disk. Reading it here means no caller has to remember.
     *
     * @throws RuntimeException
     */
    public function resumeUrl(Resume $resume, ?int $ttlMinutes = null): string
    {
        $path = is_string($resume->file_path) ? trim($resume->file_path) : '';

        if ($path === '') {
            throw new RuntimeException(
                'Resume ['.$resume->getKey().'] has no stored file path to sign; the upload never completed.'
            );
        }

        $disk = is_string($resume->storage_disk) && $resume->storage_disk !== ''
            ? $resume->storage_disk
            : null;

        return $this->temporaryUrl($path, $disk, $ttlMinutes);
    }

    /**
     * Signed URL for a tailored document's PDF, from the path persisted at
     * upload time rather than a freshly built key.
     *
     * The persisted `s3_path` is authoritative on purpose. It is what proves an
     * object was actually written, and if a key shape ever changes, older rows
     * still resolve — rebuilding the key here would quietly point at nothing.
     *
     * @throws RuntimeException
     */
    public function tailoredDocumentUrl(TailoredDocument $document, ?int $ttlMinutes = null): string
    {
        $path = trim((string) $document->s3_path);

        if ($path === '') {
            throw new RuntimeException(
                'Tailored document ['.$document->getKey().'] has no stored path to sign; it was never rendered.'
            );
        }

        return $this->temporaryUrl($path, null, $ttlMinutes);
    }

    /**
     * Resolve the TTL, clamped to at least one minute.
     *
     * A zero or negative value — an unset env var read as `(int) ''`, a typo in
     * a deploy config — would otherwise mint URLs that are already expired, and
     * that failure presents as "downloads are broken" rather than as a
     * configuration mistake.
     */
    private function ttlMinutes(?int $override = null): int
    {
        $minutes = $override ?? (int) config('filesystems.signed_url_ttl_minutes', 15);

        return max(1, $minutes);
    }

    /**
     * Identifiers come off persisted rows and are cast to `integer` by the
     * models, so no traversal is reachable through them; they are still
     * required to be present, because a key with an empty segment collides with
     * every other key missing the same segment and is indistinguishable from a
     * valid one on the bucket.
     *
     * @param  array<string, int>  $identifiers
     *
     * @throws RuntimeException
     */
    private function assertIdentifiers(array $identifiers, string $subject): void
    {
        foreach ($identifiers as $label => $value) {
            if ($value <= 0) {
                throw new RuntimeException(
                    "Cannot build an S3 key for a {$subject} without a persisted {$label}."
                );
            }
        }
    }
}
