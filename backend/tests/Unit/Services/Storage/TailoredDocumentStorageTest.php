<?php

namespace Tests\Unit\Services\Storage;

use App\Enums\TailoredDocumentType;
use App\Models\TailoredDocument;
use App\Services\Storage\TailoredDocumentStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Key shapes and upload behaviour of {@see TailoredDocumentStorage}
 * (Requirements 8.1, 8.2).
 *
 * ## Nothing here touches AWS or the database
 *
 * `Storage::fake('s3')` replaces the disk with a local one under the same name,
 * so the assertions are about *which key was written* rather than about the S3
 * driver, and no credentials, network, or bucket are involved. Documents are
 * built in memory with `forceFill()` instead of factories: the class reads two
 * integers and an enum off the model and nothing else, so a database round trip
 * would slow the suite down without testing anything more.
 *
 * The failure tests swap the facade for a mock disk, because a fake disk
 * succeeds — the two ways a real write fails (`false` return, throwing driver)
 * cannot be provoked from a working filesystem, and they are the paths most
 * worth pinning down.
 */
class TailoredDocumentStorageTest extends TestCase
{
    private TailoredDocumentStorage $storage;

    /** Scratch root for the local files handed to the upload methods. */
    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->storage = new TailoredDocumentStorage;
        $this->tempRoot = sys_get_temp_dir().'/tailored-storage-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->tempRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);

        parent::tearDown();
    }

    public function test_it_builds_the_namespaced_pdf_and_tex_keys_for_a_resume(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $this->assertSame('users/7/tailored-documents/42/resume.pdf', $this->storage->pdfKey($document));
        $this->assertSame('users/7/tailored-documents/42/resume.tex', $this->storage->texKey($document));
    }

    public function test_it_builds_hyphenated_keys_for_a_cover_letter(): void
    {
        $document = $this->document(id: 9, userId: 3, type: TailoredDocumentType::CoverLetter);

        $this->assertSame('users/3/tailored-documents/9/cover-letter.pdf', $this->storage->pdfKey($document));
        $this->assertSame('users/3/tailored-documents/9/cover-letter.tex', $this->storage->texKey($document));
    }

    /**
     * The determinism Req 8.2 asks for: the same document yields the same key
     * every time, so a key can be recomputed instead of looked up.
     */
    public function test_keys_are_deterministic_for_the_same_document(): void
    {
        $first = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);
        $second = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $this->assertSame($this->storage->pdfKey($first), $this->storage->pdfKey($second));
        $this->assertSame($this->storage->texKey($first), $this->storage->texKey($second));

        // And a second document of the same user does not collide with the first.
        $other = $this->document(id: 43, userId: 7, type: TailoredDocumentType::Resume);
        $this->assertNotSame($this->storage->pdfKey($first), $this->storage->pdfKey($other));
    }

    /**
     * The key must not depend on `application_id`, which arrives after the
     * upload — this is the whole reason the key hangs off the document id.
     */
    public function test_the_key_is_unchanged_once_an_application_is_linked(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);
        $before = $this->storage->pdfKey($document);

        $document->application_id = 501;

        $this->assertSame($before, $this->storage->pdfKey($document));
    }

    public function test_it_uploads_the_pdf_to_the_built_key_on_the_s3_disk(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);
        $localPath = $this->localFile('resume.pdf', '%PDF-1.4 tailored');

        $key = $this->storage->uploadPdf($document, $localPath);

        $this->assertSame('users/7/tailored-documents/42/resume.pdf', $key);
        Storage::disk('s3')->assertExists($key);
        $this->assertSame('%PDF-1.4 tailored', Storage::disk('s3')->get($key));
    }

    public function test_it_uploads_the_tex_source_from_a_file_and_from_a_string(): void
    {
        $fromFile = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);
        $localPath = $this->localFile('resume.tex', '\documentclass{article}');

        $fileKey = $this->storage->uploadTex($fromFile, $localPath);

        $this->assertSame('users/7/tailored-documents/42/resume.tex', $fileKey);
        Storage::disk('s3')->assertExists($fileKey);
        $this->assertSame('\documentclass{article}', Storage::disk('s3')->get($fileKey));

        $fromString = $this->document(id: 43, userId: 7, type: TailoredDocumentType::Resume);
        $stringKey = $this->storage->uploadTexSource($fromString, '\documentclass{memoir}');

        $this->assertSame('users/7/tailored-documents/43/resume.tex', $stringKey);
        $this->assertSame('\documentclass{memoir}', Storage::disk('s3')->get($stringKey));
    }

    /** Two objects, one prefix — the pair stays together on the bucket. */
    public function test_the_pdf_and_tex_land_in_the_same_prefix(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $this->storage->uploadPdf($document, $this->localFile('resume.pdf', 'pdf'));
        $this->storage->uploadTexSource($document, 'tex');

        $this->assertSame(
            ['users/7/tailored-documents/42/resume.pdf', 'users/7/tailored-documents/42/resume.tex'],
            Storage::disk('s3')->files('users/7/tailored-documents/42')
        );
    }

    /**
     * A `false` return from the disk must never be mistaken for success.
     *
     * Attempts are pinned to 1 so this test is about the `false` check and not
     * about the retry loop above it (Req 8.5, covered in
     * {@see \Tests\Unit\Services\Storage\StorageWriteRetrierTest}).
     */
    public function test_it_throws_when_the_disk_reports_a_failed_write(): void
    {
        config(['filesystems.upload_retry.attempts' => 1]);

        $this->swapDiskWith(function (Mockery\MockInterface $disk) {
            $disk->shouldReceive('put')->once()->andReturn(false);
        });

        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to store [users/7/tailored-documents/42/resume.pdf] on the "s3" disk.');

        $this->storage->uploadPdf($document, $this->localFile('resume.pdf', 'pdf'));
    }

    /** A throwing driver is reported the same way, with the cause preserved. */
    public function test_it_wraps_a_throwing_disk_in_the_same_failure(): void
    {
        config(['filesystems.upload_retry.attempts' => 1]);

        $this->swapDiskWith(function (Mockery\MockInterface $disk) {
            $disk->shouldReceive('put')->once()->andThrow(new \Exception('bucket unreachable'));
        });

        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        try {
            $this->storage->uploadTexSource($document, '\documentclass{article}');
            $this->fail('Expected a RuntimeException for a throwing disk.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('users/7/tailored-documents/42/resume.tex', $e->getMessage());
            $this->assertStringContainsString('bucket unreachable', $e->getMessage());
            $this->assertInstanceOf(\Exception::class, $e->getPrevious());
        }
    }

    /**
     * Req 8.5: a write that fails once and succeeds on the retry is not a
     * failure. The queue retry above this would have re-run the whole tailoring
     * job — model call, compile and all — for the same object, so absorbing the
     * blip here is the difference this layer makes.
     */
    public function test_a_transient_write_failure_is_retried_and_surfaces_no_error(): void
    {
        config(['filesystems.upload_retry.attempts' => 3]);

        $attempts = 0;

        $this->swapDiskWith(function (Mockery\MockInterface $disk) use (&$attempts) {
            $disk->shouldReceive('put')->times(2)->andReturnUsing(function () use (&$attempts) {
                $attempts++;

                if ($attempts === 1) {
                    throw new \Exception('503 slow down');
                }

                return true;
            });
        });

        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $key = $this->storage->uploadTexSource($document, '\documentclass{article}');

        $this->assertSame('users/7/tailored-documents/42/resume.tex', $key);
        $this->assertSame(2, $attempts);
    }

    /** The loop is bounded, and by configuration rather than by a literal. */
    public function test_the_retry_loop_is_bounded_by_configuration(): void
    {
        config(['filesystems.upload_retry.attempts' => 2]);

        $this->swapDiskWith(function (Mockery\MockInterface $disk) {
            $disk->shouldReceive('put')->times(2)->andReturn(false);
        });

        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to store [users/7/tailored-documents/42/resume.tex] on the "s3" disk.');

        $this->storage->uploadTexSource($document, '\documentclass{article}');
    }

    /**
     * Bad credentials will be bad credentials after a sleep, so the failure is
     * reported on the first attempt instead of being delayed by the loop.
     */
    public function test_a_non_retryable_failure_is_not_retried(): void
    {
        config(['filesystems.upload_retry.attempts' => 3]);

        $this->swapDiskWith(function (Mockery\MockInterface $disk) {
            $disk->shouldReceive('put')
                ->once()
                ->andThrow(new FakeAwsFailure('access denied', awsErrorCode: 'AccessDenied'));
        });

        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('access denied');

        $this->storage->uploadTexSource($document, '\documentclass{article}');
    }

    /**
     * A missing local file is a caller bug, not a storage outage, and is
     * rejected before the disk is touched.
     */
    public function test_it_throws_before_writing_when_the_local_file_is_missing(): void
    {
        $document = $this->document(id: 42, userId: 7, type: TailoredDocumentType::Resume);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the local file is missing or unreadable');

        try {
            $this->storage->uploadPdf($document, $this->tempRoot.'/nope.pdf');
        } finally {
            Storage::disk('s3')->assertMissing('users/7/tailored-documents/42/resume.pdf');
        }
    }

    /** An unpersisted document has no id to key on, so it is refused outright. */
    public function test_it_refuses_to_build_a_key_without_a_persisted_id(): void
    {
        $document = new TailoredDocument(['user_id' => 7, 'type' => TailoredDocumentType::Resume]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a persisted id and user id');

        $this->storage->pdfKey($document);
    }

    /**
     * An in-memory stand-in for a persisted `tailored_documents` row — only the
     * three attributes the class reads are set.
     */
    private function document(int $id, int $userId, TailoredDocumentType $type): TailoredDocument
    {
        $document = new TailoredDocument;
        $document->forceFill([
            'id' => $id,
            'user_id' => $userId,
            'type' => $type,
        ]);
        $document->exists = true;

        return $document;
    }

    private function localFile(string $name, string $contents): string
    {
        $path = $this->tempRoot.'/'.$name;
        File::put($path, $contents);

        return $path;
    }

    /**
     * Replace the `s3` disk with a mock, for the two failures a working
     * filesystem cannot produce.
     */
    private function swapDiskWith(callable $expectations): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $expectations($disk);

        Storage::shouldReceive('disk')->with('s3')->andReturn($disk);
    }
}
