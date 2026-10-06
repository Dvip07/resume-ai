<?php

namespace App\Http\Controllers\Api;

use App\Enums\ResumeStatus;
use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeResumeJob;
use App\Models\Resume;
use App\Services\Storage\StorageWriteRetrier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\PdfToText\Pdf;

/**
 * JSON API surface for resumes (Sanctum bearer-token auth).
 *
 * Reuses the upload/parse logic from the Blade-era
 * App\Http\Controllers\ResumeController (PDF text extraction +
 * AnalyzeResumeJob dispatch) but scopes every operation to the
 * authenticated user and returns JSON instead of redirects/views.
 *
 * Validates: Requirements 2.1, 2.4
 */
class ResumeController extends Controller
{
    /**
     * List the authenticated user's resumes (paginated).
     *
     * Each row carries `status` (uploaded|parsing|parsed|failed — see
     * App\Enums\ResumeStatus) and, when `failed`, a `status_error` reason the
     * frontend can display, so a parse failure is never silent
     * (Requirement 2.4).
     */
    public function index(Request $request): JsonResponse
    {
        $resumes = Resume::query()
            ->where('user_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return response()->json($resumes);
    }

    /**
     * Upload a resume (PDF), store it on the configured artifact disk
     * (`s3` by default), extract its text, and dispatch the async parsing
     * job — scoped to the authenticated user.
     *
     * Ordering note (Requirement 8.2): the object key is deterministic and
     * contains the resume id (`users/{user_id}/resumes/original/{id}.pdf`),
     * so the `resumes` row must exist before the upload. The row is therefore
     * created first with an empty `file_path` and status `uploaded`, then
     * updated to the real key + `parsing` once the object is stored. A row
     * whose upload fails is left as `failed` rather than deleted, so the
     * failure is visible to the user (Requirement 2.4).
     *
     * Text extraction happens before the upload, against PHP's own upload
     * temp file (`getRealPath()`), because `pdftotext` needs a local path and
     * an S3 key is not one. PHP removes that temp file at the end of the
     * request, so no temp copies are left behind.
     *
     * ## What Requirement 8.5 means with no queue underneath (Req 8.5)
     *
     * 8.5 asks for retry with backoff and for the step not to be marked
     * complete until storage succeeds, and it names the queued-job retry as the
     * mechanism. This path has no queue: a human is holding an open HTTP
     * request. Both halves still apply, differently.
     *
     * - *Retry with backoff* is done in-process by {@see StorageWriteRetrier},
     *   with the lower `interactive_attempts` cap, because here the retry loop
     *   is the only retry there will ever be — nothing re-runs a controller —
     *   but it is also happening inside a request that must answer. A short
     *   bounded loop rides out a throttle; anything longer would just be a
     *   hung upload, and the user's own retry is cheap (re-POST the file) in a
     *   way a tailoring job's retry is not.
     * - *Not marking the step complete* is why the row is left `failed` with an
     *   empty `file_path` rather than advanced to `parsing`, and why
     *   `AnalyzeResumeJob` is not dispatched. The stage that depends on the
     *   object never starts, and the failure is visible to the user
     *   (Requirement 2.4) instead of surfacing later as a broken download.
     */
    public function store(Request $request, StorageWriteRetrier $retrier): JsonResponse
    {
        $validated = $request->validate([
            'resume' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $resumeFile = $validated['resume'];
        $originalFileName = $resumeFile->getClientOriginalName();

        try {
            $pdf = new Pdf;
            $resumeText = $pdf->setPdf($resumeFile->getRealPath())->text();
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error extracting text from resume.',
            ], 422);
        }

        $disk = config('filesystems.resume_disk', 's3');

        $resume = Resume::create([
            'user_id' => $request->user()->id,
            'original_filename' => $originalFileName,
            'file_path' => '',
            'storage_disk' => $disk,
            'is_optimized' => false,
            'status' => ResumeStatus::Uploaded,
        ]);

        $key = $this->originalResumeKey($resume);

        try {
            $stored = $retrier->write(
                $disk,
                $key,
                fn () => Storage::disk($disk)->putFileAs(
                    dirname($key),
                    $resumeFile,
                    basename($key)
                ),
                attempts: (int) config('filesystems.upload_retry.interactive_attempts', 2),
            );
        } catch (\Throwable $e) {
            $stored = false;
        }

        if ($stored === false) {
            $resume->markFailed('Could not store the uploaded resume file on the "'.$disk.'" disk.');

            return response()->json([
                'message' => 'Could not store the resume file. Please try again.',
                'resume' => $resume->fresh(),
            ], 500);
        }

        $resume->update([
            'file_path' => $key,
            'status' => ResumeStatus::Parsing,
            'status_error' => null,
        ]);

        AnalyzeResumeJob::dispatch($resume, $resumeText);

        return response()->json([
            'message' => 'Resume uploaded. Analysis is in progress.',
            'resume' => $resume->fresh(),
        ], 201);
    }

    /**
     * Deterministic object key for an original resume upload (Req 8.2):
     * `users/{user_id}/resumes/original/{resume_id}.pdf`.
     */
    private function originalResumeKey(Resume $resume): string
    {
        return "users/{$resume->user_id}/resumes/original/{$resume->id}.pdf";
    }

    /**
     * Show a single resume owned by the authenticated user.
     */
    public function show(Request $request, Resume $resume): JsonResponse
    {
        if ($resume->user_id !== $request->user()->id) {
            abort(404);
        }

        return response()->json($resume);
    }

    /**
     * Delete a resume owned by the authenticated user.
     */
    public function destroy(Request $request, Resume $resume): JsonResponse
    {
        if ($resume->user_id !== $request->user()->id) {
            abort(404);
        }

        $resume->delete();

        return response()->json([
            'message' => 'Resume deleted.',
        ]);
    }
}
