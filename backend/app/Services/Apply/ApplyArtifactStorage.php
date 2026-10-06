<?php

namespace App\Services\Apply;

use App\Services\Storage\StorageWriteRetrier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Puts the screenshots an apply run captured into object storage and hands back
 * their keys (Requirement 9.5).
 *
 * The worker returns screenshots inline as base64 PNGs, so the bytes only exist
 * for as long as the {@see ApplyRunResult} does — which is why persistence sits
 * with the adapter rather than with the `SubmitApplication` job: by the time the
 * job sees an {@see ApplyResult}, the DTO carries keys, not images.
 *
 * Keys hang off the ids that already exist on the attempt:
 *
 *     users/{user_id}/applications/{job_listing_id}/apply/{adapter}/{seq}-{name}.png
 *
 * The `users/{user_id}/` prefix is the same one every other artefact shape uses,
 * which is what lets `S3StorageService::deleteForOwner()` and a per-user bucket
 * lifecycle rule cover these too without knowing they exist. The sequence number
 * preserves capture order even when two steps shared a name, and keying on the
 * job listing rather than the application id avoids the problem argued out in
 * {@see \App\Services\Storage\TailoredDocumentStorage} — an `applications` row
 * may not exist yet, or at all.
 *
 * ## A failed upload is logged, not raised
 *
 * The opposite call to the one tailoring makes, and for a different reason: a
 * tailored PDF *is* the deliverable, while a screenshot documents a form
 * submission that has already happened. Throwing here would turn a storage
 * hiccup into a retried apply run, and a retried apply run is a duplicate
 * application — the one outcome worth more than a complete audit trail. So each
 * write is guarded independently, failures are logged, and the adapter reports
 * whatever keys did land.
 */
class ApplyArtifactStorage
{
    private StorageWriteRetrier $retrier;

    public function __construct(?StorageWriteRetrier $retrier = null)
    {
        $this->retrier = $retrier ?? new StorageWriteRetrier;
    }

    /**
     * @param  array<int, ApplyScreenshot>  $screenshots
     * @return list<string> keys, in capture order
     */
    public function storeScreenshots(ApplyContext $context, string $adapter, array $screenshots): array
    {
        $userId = (int) $context->user->getKey();
        $jobId = (int) $context->job->getKey();

        if ($userId <= 0 || $jobId <= 0 || $screenshots === []) {
            return [];
        }

        $disk = $this->disk();
        $prefix = "users/{$userId}/applications/{$jobId}/apply/".$this->slug($adapter).'/';
        $stored = [];
        $sequence = 0;

        foreach ($screenshots as $screenshot) {
            if (! $screenshot instanceof ApplyScreenshot) {
                continue;
            }

            $sequence++;
            $key = $prefix.$sequence.'-'.$this->slug($screenshot->name).'.'.$this->slug($screenshot->format);

            try {
                $written = $this->retrier->write(
                    $disk,
                    $key,
                    fn (): bool => (bool) Storage::disk($disk)->put($key, $screenshot->bytes)
                );

                if ($written) {
                    $stored[] = $key;

                    continue;
                }

                $this->logFailure($disk, $key, 'the disk reported the write as unsuccessful');
            } catch (Throwable $e) {
                $this->logFailure($disk, $key, $e->getMessage());
            }
        }

        return $stored;
    }

    private function disk(): string
    {
        $disk = config('apply.screenshot_disk', 's3');

        return is_string($disk) && $disk !== '' ? $disk : 's3';
    }

    /**
     * The worker already sanitises screenshot names, but they originate in an
     * adapter's step script and end up in a key, so they are normalised again
     * here rather than trusted across a process boundary.
     */
    private function slug(string $value): string
    {
        $slug = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $value) ?? '';
        $slug = trim($slug, '-.');

        return $slug === '' ? 'screenshot' : mb_substr($slug, 0, 80);
    }

    private function logFailure(string $disk, string $key, string $reason): void
    {
        Log::warning('apply.screenshot_store_failed', [
            'disk' => $disk,
            'key' => $key,
            'reason' => $reason,
            'consequence' => 'the apply run is unaffected; this screenshot is missing from the audit trail',
        ]);
    }
}
