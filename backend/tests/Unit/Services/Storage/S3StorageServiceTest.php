<?php

namespace Tests\Unit\Services\Storage;

use App\Enums\TailoredDocumentType;
use App\Models\Resume;
use App\Models\TailoredDocument;
use App\Services\Storage\S3StorageService;
use App\Services\Storage\TailoredDocumentStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Key structure and signed-URL generation in {@see S3StorageService}
 * (Requirements 8.2, 8.3).
 *
 * Validates: Requirements 8.2, 8.3
 *
 * ## Scope, and what is deliberately not here
 *
 * `deleteForOwner()` and the observers wired to it are covered end to end in
 * {@see \Tests\Feature\Services\StorageDeletionTest} against real rows, which is
 * the only way the database-level cascade and the cross-user blast radius can be
 * tested at all. This file is the other half: the two pure-function surfaces —
 * where an object lives, and how a URL to it is minted — neither of which needs
 * a database.
 *
 * ## Nothing here touches AWS or the database
 *
 * `Storage::fake()` swaps each disk for a local one under the same name and
 * registers a temporary-URL callback, so signing *works* under a fake. That is
 * convenient for the happy path and a trap for the fallback branch, which is
 * therefore given a genuinely non-signing disk (a `local` driver with a `url`,
 * exactly the `RESUME_STORAGE_DISK=public` development setup the fallback exists
 * for) rather than being coaxed out of a fake.
 *
 * The fakes' callbacks are overridden to name the disk they came from. Both
 * disks would otherwise mint identical URLs for the same key, and "which disk
 * signed this" is precisely what {@see S3StorageService::resumeUrl()} is
 * responsible for getting right.
 *
 * Models are built in memory with `forceFill()`: the service reads two integers,
 * an enum and a couple of string columns off each row and nothing else.
 */
class S3StorageServiceTest extends TestCase
{
    private S3StorageService $storage;

    /** Scratch root for the non-signing local disk. */
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // Pinned rather than inherited from .env, so the assertions about which
        // disk was used do not depend on a developer's local fallback setting.
        config(['filesystems.resume_disk' => 's3']);
        config(['filesystems.signed_url_ttl_minutes' => 15]);

        $this->fakeSigningDisk('s3');
        $this->fakeSigningDisk('public');

        $this->tempRoot = sys_get_temp_dir().'/s3-storage-service-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tempRoot);

        $this->storage = new S3StorageService;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    // ---------------------------------------------------------------- keys

    public function test_it_builds_the_original_resume_key(): void
    {
        $this->assertSame(
            'users/7/resumes/original/42.pdf',
            $this->storage->originalResumeKey($this->resume(id: 42, userId: 7))
        );
    }

    public function test_it_builds_the_tailored_document_key_for_each_type_and_extension(): void
    {
        $resume = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);
        $coverLetter = $this->document(id: 43, userId: 7, type: TailoredDocumentType::CoverLetter);

        $this->assertSame(
            'users/7/tailored-documents/42/resume.pdf',
            $this->storage->tailoredDocumentKey($resume)
        );
        $this->assertSame(
            'users/7/tailored-documents/42/resume.tex',
            $this->storage->tailoredDocumentKey($resume, 'tex')
        );
        $this->assertSame(
            'users/7/tailored-documents/43/cover-letter.pdf',
            $this->storage->tailoredDocumentKey($coverLetter)
        );
        $this->assertSame(
            'users/7/tailored-documents/43/cover-letter.tex',
            $this->storage->tailoredDocumentKey($coverLetter, 'tex')
        );
    }

    /** Every shape shares the prefix the user-level delete and lifecycle rules rely on. */
    public function test_it_builds_the_user_prefix_and_every_key_sits_under_it(): void
    {
        $prefix = $this->storage->userPrefix(7);

        $this->assertSame('users/7/', $prefix);

        foreach ([
            $this->storage->originalResumeKey($this->resume(id: 42, userId: 7)),
            $this->storage->tailoredDocumentKey($this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume)),
            $this->storage->tailoredDocumentKey($this->document(id: 43, userId: 7, type: TailoredDocumentType::CoverLetter), 'tex'),
        ] as $key) {
            $this->assertStringStartsWith($prefix, $key);
        }

        // And a neighbouring id is not covered by it — `users/7/` must not be a
        // prefix of `users/70/...`, or a user delete would reach into another
        // user's objects.
        $this->assertStringStartsNotWith(
            $prefix,
            $this->storage->originalResumeKey($this->resume(id: 1, userId: 70))
        );
    }

    /**
     * The class docblock states the tailored key here is a transcription of
     * {@see TailoredDocumentStorage::key()} and that the two must stay
     * byte-identical until delegation lands. Asserting it is the difference
     * between a promise and a guarantee: objects are already on the bucket under
     * that shape, and a drift between the two classes would have one of them
     * pointing at nothing.
     */
    public function test_the_tailored_document_key_is_byte_identical_to_tailored_document_storages(): void
    {
        $tailoring = new TailoredDocumentStorage;

        foreach ([TailoredDocumentType::Resume, TailoredDocumentType::CoverLetter] as $index => $type) {
            $document = $this->document(id: 100 + $index, userId: 7, type: $type);

            $this->assertSame(
                $tailoring->pdfKey($document),
                $this->storage->tailoredDocumentKey($document, 'pdf'),
                "pdf key drifted for {$type->value}"
            );
            $this->assertSame(
                $tailoring->texKey($document),
                $this->storage->tailoredDocumentKey($document, 'tex'),
                "tex key drifted for {$type->value}"
            );
        }
    }

    /**
     * A key with an empty segment collides with every other key missing the same
     * segment and is indistinguishable from a valid one on the bucket, so an
     * unpersisted row is refused rather than keyed optimistically.
     */
    public function test_it_refuses_to_build_a_key_for_an_unpersisted_resume(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a persisted resume id');

        $this->storage->originalResumeKey(new Resume(['user_id' => 7]));
    }

    public function test_it_refuses_to_build_a_key_for_a_resume_without_a_user(): void
    {
        $resume = $this->resume(id: 42, userId: 0);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a persisted user id');

        $this->storage->originalResumeKey($resume);
    }

    public function test_it_refuses_to_build_a_key_for_an_unpersisted_tailored_document(): void
    {
        $document = new TailoredDocument(['user_id' => 7, 'type' => TailoredDocumentType::Resume]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a persisted document id');

        $this->storage->tailoredDocumentKey($document);
    }

    public function test_it_refuses_a_user_prefix_for_a_zero_id(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a persisted user id');

        $this->storage->userPrefix(0);
    }

    /**
     * The extension is the only segment of the key not derived from a cast
     * integer, so it is the only one a caller could put something unexpected
     * through. It is whitelisted, not interpolated.
     */
    public function test_it_rejects_an_unsupported_extension(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        foreach (['', 'docx', '../../etc/passwd', 'pdf?x=1', 'PDF'] as $extension) {
            try {
                $this->storage->tailoredDocumentKey($document, $extension);
                $this->fail("Expected [{$extension}] to be rejected as an extension.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('unsupported extension', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------ signed URLs

    public function test_it_mints_a_signed_url_for_a_key_with_the_configured_ttl(): void
    {
        $this->freezeTime();
        config(['filesystems.signed_url_ttl_minutes' => 45]);

        $url = $this->storage->temporaryUrl('users/7/resumes/original/42.pdf');

        $this->assertStringStartsWith('https://s3.test/users/7/resumes/original/42.pdf', $url);
        $this->assertSame(now()->addMinutes(45)->getTimestamp(), $this->expirationOf($url));
    }

    public function test_an_explicit_ttl_overrides_the_configured_one(): void
    {
        $this->freezeTime();
        config(['filesystems.signed_url_ttl_minutes' => 15]);

        $url = $this->storage->temporaryUrl('users/7/resumes/original/42.pdf', ttlMinutes: 120);

        $this->assertSame(now()->addMinutes(120)->getTimestamp(), $this->expirationOf($url));
    }

    /**
     * An unset env var read as `(int) ''`, or a typo in a deploy config, must not
     * mint a URL that is already expired — that failure presents as "downloads
     * are broken" rather than as a configuration mistake.
     */
    public function test_a_zero_or_negative_ttl_is_clamped_instead_of_expiring_immediately(): void
    {
        $this->freezeTime();

        foreach ([0, -1, -600] as $configured) {
            config(['filesystems.signed_url_ttl_minutes' => $configured]);

            $expiration = $this->expirationOf($this->storage->temporaryUrl('users/7/resumes/original/42.pdf'));

            $this->assertGreaterThan(now()->getTimestamp(), $expiration, "TTL [{$configured}] minted a dead URL");
            $this->assertSame(now()->addMinute()->getTimestamp(), $expiration);
        }

        // The override path is clamped by the same helper, not just the config path.
        $this->assertSame(
            now()->addMinute()->getTimestamp(),
            $this->expirationOf($this->storage->temporaryUrl('users/7/resumes/original/42.pdf', ttlMinutes: 0))
        );
    }

    public function test_it_refuses_to_sign_an_empty_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty object key');

        $this->storage->temporaryUrl('   ');
    }

    /**
     * `resumes.storage_disk` records where the upload actually went, so it wins
     * over the current configuration — a caller that forgot to pass it would
     * otherwise get a URL for the wrong bucket on any machine using the fallback
     * disk.
     */
    public function test_resume_url_signs_on_the_disk_the_row_was_written_to(): void
    {
        $onPublic = $this->resume(id: 42, userId: 7, overrides: [
            'file_path' => 'users/7/resumes/original/42.pdf',
            'storage_disk' => 'public',
        ]);

        $this->assertStringStartsWith(
            'https://public.test/users/7/resumes/original/42.pdf',
            $this->storage->resumeUrl($onPublic)
        );

        // A blank column falls back to the configured artefact disk.
        $unrecorded = $this->resume(id: 43, userId: 7, overrides: [
            'file_path' => 'users/7/resumes/original/43.pdf',
            'storage_disk' => '',
        ]);

        $this->assertStringStartsWith(
            'https://s3.test/users/7/resumes/original/43.pdf',
            $this->storage->resumeUrl($unrecorded)
        );
    }

    public function test_resume_url_signs_the_persisted_path_and_honours_the_ttl(): void
    {
        $this->freezeTime();

        $resume = $this->resume(id: 42, userId: 7, overrides: [
            // A legacy row whose path is not the deterministic key.
            'file_path' => 'users/7/resumes/original/legacy-name.pdf',
            'storage_disk' => 's3',
        ]);

        $url = $this->storage->resumeUrl($resume, ttlMinutes: 5);

        $this->assertStringStartsWith('https://s3.test/users/7/resumes/original/legacy-name.pdf', $url);
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $this->expirationOf($url));
    }

    public function test_resume_url_throws_when_the_upload_never_completed(): void
    {
        $resume = $this->resume(id: 42, userId: 7, overrides: ['file_path' => '', 'storage_disk' => 's3']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no stored file path to sign');

        $this->storage->resumeUrl($resume);
    }

    /**
     * The persisted `s3_path` is authoritative: it is what proves an object was
     * written, and older rows must keep resolving if a key shape ever changes.
     * Pinned with a path that deliberately differs from the rebuilt key, so the
     * assertion can tell the two apart.
     */
    public function test_tailored_document_url_signs_the_persisted_path_not_a_rebuilt_key(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume, overrides: [
            's3_path' => 'users/7/legacy-tailored/42/resume-v1.pdf',
        ]);

        $rebuilt = $this->storage->tailoredDocumentKey($document, 'pdf');
        $url = $this->storage->tailoredDocumentUrl($document);

        $this->assertStringStartsWith('https://s3.test/users/7/legacy-tailored/42/resume-v1.pdf', $url);
        $this->assertStringNotContainsString($rebuilt, $url);
    }

    public function test_tailored_document_url_throws_when_the_document_was_never_rendered(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume, overrides: ['s3_path' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no stored path to sign; it was never rendered');

        $this->storage->tailoredDocumentUrl($document);
    }

    /**
     * The documented local-development fallback: a real `local`/`public` disk
     * cannot sign, and throwing here would make every screen that shows a
     * document unusable without AWS credentials. The warning is what keeps a
     * misconfigured *production* deploy from serving non-expiring URLs silently.
     */
    public function test_it_serves_an_unsigned_url_and_warns_on_a_disk_that_cannot_sign(): void
    {
        config(['filesystems.disks.nonsigning' => [
            'driver' => 'local',
            'root' => $this->tempRoot,
            'url' => 'https://cdn.test/artifacts',
            'throw' => false,
        ]]);

        Log::spy();

        $url = $this->storage->temporaryUrl('users/7/resumes/original/42.pdf', 'nonsigning');

        $this->assertSame('https://cdn.test/artifacts/users/7/resumes/original/42.pdf', $url);
        $this->assertStringNotContainsString('expiration', $url);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, 'Signed URL unavailable')
                    && $context['disk'] === 'nonsigning'
                    && $context['key'] === 'users/7/resumes/original/42.pdf';
            });
    }

    /**
     * The fallback has a floor: a disk that can produce neither URL is an error,
     * not a silently broken link.
     *
     * The disk is a stand-in rather than a configured driver because every driver
     * this app ships can produce at least one of the two — `local` always falls
     * back to a `/storage/...` path — so the branch is unreachable from
     * `config/filesystems.php` and would otherwise go untested.
     */
    public function test_it_throws_when_the_disk_can_produce_neither_kind_of_url(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('temporaryUrl')->once()->andThrow(new RuntimeException('no temporary URLs'));
        $disk->shouldReceive('url')->once()->andThrow(new RuntimeException('no public URLs'));

        Storage::set('unusable', $disk);

        try {
            $this->storage->temporaryUrl('users/7/resumes/original/42.pdf', 'unusable');
            $this->fail('Expected a RuntimeException when neither URL kind is available.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('users/7/resumes/original/42.pdf', $e->getMessage());
            $this->assertStringContainsString('supports neither temporary nor public URLs', $e->getMessage());
            $this->assertStringContainsString('no temporary URLs', $e->getMessage());
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A faked disk whose temporary URLs name the disk that minted them, so
     * "which disk signed this" is answerable. The `expiration` query parameter
     * keeps the shape `Storage::fake()`'s own callback uses.
     */
    private function fakeSigningDisk(string $name): void
    {
        Storage::fake($name)->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expiration) => "https://{$name}.test/{$path}?expiration=".$expiration->getTimestamp()
        );
    }

    private function expirationOf(string $url): int
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('expiration', $query, "No expiration on [{$url}]; it was not signed.");

        return (int) $query['expiration'];
    }

    private function resume(int $id, int $userId, array $overrides = []): Resume
    {
        $resume = new Resume;
        $resume->forceFill(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'file_path' => "users/{$userId}/resumes/original/{$id}.pdf",
            'storage_disk' => 's3',
        ], $overrides));
        $resume->exists = true;

        return $resume;
    }

    private function document(int $id, int $userId, TailoredDocumentType $type, array $overrides = []): TailoredDocument
    {
        $document = new TailoredDocument;
        $document->forceFill(array_merge([
            'id' => $id,
            'user_id' => $userId,
            'type' => $type,
            's3_path' => "users/{$userId}/tailored-documents/{$id}/".($type === TailoredDocumentType::Resume ? 'resume' : 'cover-letter').'.pdf',
        ], $overrides));
        $document->exists = true;

        return $document;
    }
}
