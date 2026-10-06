<?php

namespace Tests\Feature\Services;

use App\Enums\PipelineStage;
use App\Enums\TailoredDocumentStatus;
use App\Enums\TailoredDocumentType;
use App\Models\Application;
use App\Models\JobListing;
use App\Models\Resume;
use App\Models\TailoredDocument;
use App\Models\User;
use App\Services\Storage\S3StorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Object cleanup on record deletion: {@see S3StorageService::deleteForOwner()}
 * and the observers wired to it in `AppServiceProvider::boot()`.
 *
 * Validates: Requirement 8.4
 *
 * ## Nothing here touches a bucket
 *
 * `Storage::fake('s3')` swaps the disk for a local one under the same name
 * before every test, so "was the object deleted" is answered against a temporary
 * directory. In the testing environment `filesystems.resume_disk` resolves to
 * `s3` as well, so originals and tailored artefacts share the single faked disk —
 * which is the production arrangement too.
 *
 * The two tests that matter most are the negative ones: a second user's objects
 * surviving a delete, and the database-level cascade that these observers cannot
 * see. Both exist to stop a future change from quietly widening the blast radius.
 */
class StorageDeletionTest extends TestCase
{
    use RefreshDatabase;

    private S3StorageService $storage;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');

        $this->storage = app(S3StorageService::class);
    }

    public function test_deleting_a_resume_removes_its_object(): void
    {
        $resume = $this->makeResume($this->makeUser());
        Storage::disk('s3')->put($resume->file_path, '%PDF-1.4 original');
        Storage::disk('s3')->assertExists($resume->file_path);

        $resume->delete();

        Storage::disk('s3')->assertMissing($resume->file_path);
    }

    public function test_deleting_a_tailored_document_removes_both_the_pdf_and_the_tex_source(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument($user, $this->makeListing());

        Storage::disk('s3')->put($document->s3_path, '%PDF-1.4 tailored');
        Storage::disk('s3')->put($document->tex_source_s3_path, '\documentclass{article}');

        $document->delete();

        Storage::disk('s3')->assertMissing($document->s3_path);
        Storage::disk('s3')->assertMissing($document->tex_source_s3_path);
    }

    /**
     * The blast-radius test. Two users' keys differ only in the id segment, so a
     * prefix delete one level too high — or a rebuilt key using the wrong id —
     * would take both out.
     */
    public function test_one_users_delete_does_not_touch_another_users_objects(): void
    {
        $listing = $this->makeListing();

        $mine = $this->makeUser();
        $myResume = $this->makeResume($mine);
        $myDocument = $this->makeDocument($mine, $listing);

        $theirs = $this->makeUser();
        $theirResume = $this->makeResume($theirs);
        $theirDocument = $this->makeDocument($theirs, $listing);

        foreach ([$myResume, $theirResume] as $resume) {
            Storage::disk('s3')->put($resume->file_path, 'original');
        }

        foreach ([$myDocument, $theirDocument] as $document) {
            Storage::disk('s3')->put($document->s3_path, 'pdf');
            Storage::disk('s3')->put($document->tex_source_s3_path, 'tex');
        }

        $myResume->delete();
        $myDocument->delete();

        Storage::disk('s3')->assertMissing($myResume->file_path);
        Storage::disk('s3')->assertMissing($myDocument->s3_path);

        Storage::disk('s3')->assertExists($theirResume->file_path);
        Storage::disk('s3')->assertExists($theirDocument->s3_path);
        Storage::disk('s3')->assertExists($theirDocument->tex_source_s3_path);
    }

    /**
     * Deleting a record whose object was never written — a `pending` document, an
     * upload that died before `Storage::put()` — must be an ordinary delete, not
     * an error. This is the common case in the failure paths elsewhere in the
     * pipeline.
     */
    public function test_a_missing_object_does_not_fail_the_delete(): void
    {
        $user = $this->makeUser();
        $document = $this->makeDocument($user, $this->makeListing(), [
            's3_path' => '',
            'tex_source_s3_path' => null,
            'status' => TailoredDocumentStatus::Pending,
        ]);
        $resume = $this->makeResume($user);

        $document->delete();
        $resume->delete();

        $this->assertDatabaseMissing('tailored_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('resumes', ['id' => $resume->id]);
    }

    /**
     * The one whole-prefix delete, and the only cleanup available for a user:
     * their `resumes` and `tailored_documents` rows are removed by the database
     * cascade, so no per-row observer sees them.
     */
    public function test_deleting_a_user_removes_their_whole_prefix_and_leaves_other_users_alone(): void
    {
        $listing = $this->makeListing();

        $doomed = $this->makeUser();
        $doomedResume = $this->makeResume($doomed);
        $doomedDocument = $this->makeDocument($doomed, $listing);

        $survivor = $this->makeUser();
        $survivorResume = $this->makeResume($survivor);

        Storage::disk('s3')->put($doomedResume->file_path, 'original');
        Storage::disk('s3')->put($doomedDocument->s3_path, 'pdf');
        Storage::disk('s3')->put($doomedDocument->tex_source_s3_path, 'tex');
        Storage::disk('s3')->put("users/{$doomed->id}/something-else/stray.pdf", 'stray');
        Storage::disk('s3')->put($survivorResume->file_path, 'original');

        $doomed->delete();

        $this->assertSame([], Storage::disk('s3')->allFiles("users/{$doomed->id}"));
        Storage::disk('s3')->assertExists($survivorResume->file_path);
    }

    /**
     * Documents the coverage gap honestly: Eloquent events fire only for models
     * deleted through Eloquent, so a database-level cascade removes rows with no
     * observer involvement and the objects survive.
     *
     * `job_listings` is the case with no compensating cleanup —
     * `tailored_documents` cascades from it, and unlike a user delete there is no
     * single prefix that bounds "this listing's documents", since they belong to
     * many users. Job listings are deactivated rather than deleted today; if that
     * ever changes, the delete path has to load the documents and `->delete()`
     * them through Eloquent. This test fails the moment that gap is closed, which
     * is the point — it is a tripwire, not an endorsement.
     */
    public function test_a_database_level_cascade_does_not_fire_the_document_observer(): void
    {
        $listing = $this->makeListing();
        $document = $this->makeDocument($this->makeUser(), $listing);

        Storage::disk('s3')->put($document->s3_path, 'pdf');

        $listing->delete();

        $this->assertDatabaseMissing('tailored_documents', ['id' => $document->id]);
        Storage::disk('s3')->assertExists($document->s3_path);
    }

    /**
     * An application owns no object of its own, and the tailored document it
     * pointed at survives it (`nullOnDelete`) — so deleting an application must
     * leave the PDF alone, or that surviving row would point at nothing.
     */
    public function test_deleting_an_application_leaves_the_tailored_documents_objects_in_place(): void
    {
        $user = $this->makeUser();
        $listing = $this->makeListing();
        $document = $this->makeDocument($user, $listing);

        $application = Application::create([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'tailored_resume_id' => $document->id,
            'status' => 'submitted',
        ]);
        $document->update(['application_id' => $application->id]);

        Storage::disk('s3')->put($document->s3_path, 'pdf');

        $application->delete();

        Storage::disk('s3')->assertExists($document->s3_path);
        $this->assertDatabaseHas('tailored_documents', ['id' => $document->id]);
        $this->assertSame([], $this->storage->deleteForOwner($application->fresh() ?? $application));
    }

    /**
     * A resume whose `file_path` drifted from the deterministic key — an upload
     * that wrote the object and then failed to persist the path, or a legacy row
     * — still has both keys cleaned up, because they belong to that one row.
     */
    public function test_it_deletes_both_the_persisted_path_and_the_rebuilt_key_for_a_resume(): void
    {
        $user = $this->makeUser();
        $resume = $this->makeResume($user, ['file_path' => "users/{$user->id}/resumes/original/legacy-name.pdf"]);

        $rebuilt = $this->storage->originalResumeKey($resume);
        Storage::disk('s3')->put($resume->file_path, 'legacy');
        Storage::disk('s3')->put($rebuilt, 'orphan');

        $deleted = $this->storage->deleteForOwner($resume);

        $this->assertEqualsCanonicalizing([$resume->file_path, $rebuilt], $deleted);
        Storage::disk('s3')->assertMissing($resume->file_path);
        Storage::disk('s3')->assertMissing($rebuilt);
    }

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Dev Patel',
            'email' => 'dev'.uniqid().'@example.com',
            'password' => 'password',
        ]);
    }

    private function makeListing(): JobListing
    {
        return JobListing::create([
            'api_id' => 'job-'.uniqid(),
            'title' => 'Senior Laravel Engineer',
            'company' => 'Acme',
            'location' => 'Toronto, Ontario, Canada',
            'description' => 'Build things.',
            'api_source' => 'adzuna',
            'source_key' => 'adzuna',
            'is_active' => true,
            'parsed_skills' => ['php'],
            'application_url' => 'https://jobs.example.com/careers/1',
            'pipeline_stage' => PipelineStage::Tailoring,
        ]);
    }

    /**
     * A persisted resume whose `file_path` is the deterministic key, which is
     * what a healthy upload leaves behind.
     */
    private function makeResume(User $user, array $overrides = []): Resume
    {
        $resume = Resume::create(array_merge([
            'user_id' => $user->id,
            'original_filename' => 'resume.pdf',
            'file_path' => '',
            'storage_disk' => 's3',
        ], $overrides));

        if (! array_key_exists('file_path', $overrides)) {
            $resume->update(['file_path' => $this->storage->originalResumeKey($resume)]);
        }

        return $resume;
    }

    private function makeDocument(User $user, JobListing $listing, array $overrides = []): TailoredDocument
    {
        $document = TailoredDocument::create(array_merge([
            'user_id' => $user->id,
            'job_listing_id' => $listing->id,
            'type' => TailoredDocumentType::Resume,
            'template_key' => 'modern',
            's3_path' => '',
            'generation_model' => 'anthropic/claude-3.5-sonnet',
            'generation_tier' => 'premium',
            'status' => TailoredDocumentStatus::Rendered,
        ], $overrides));

        if (! array_key_exists('s3_path', $overrides)) {
            $document->update([
                's3_path' => $this->storage->tailoredDocumentKey($document, 'pdf'),
                'tex_source_s3_path' => $this->storage->tailoredDocumentKey($document, 'tex'),
            ]);
        }

        return $document;
    }
}
