<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Storage\S3StorageService;

/**
 * Removes everything a deleted user owns in storage, by prefix
 * (Requirement 8.4).
 *
 * ## Why this one is a prefix delete when nothing else is
 *
 * Not for convenience. `resumes.user_id` and `tailored_documents.user_id` both
 * cascade from `users`, so by the time a user is deleted the database has removed
 * every row that recorded a key — and it removed them without Eloquent, so
 * neither {@see ResumeObserver} nor {@see TailoredDocumentObserver} runs. There
 * is no list of named keys left to delete. The choice is a whole-prefix delete or
 * orphaning every object that user ever had.
 *
 * `users/{user_id}/` is safe as a unit in a way no shorter prefix is: every key
 * shape in {@see S3StorageService} is defined to start with it, and it contains
 * that user's objects and nothing else. The reason it must never be used for a
 * single-record delete is the same reason it is right here — it covers
 * *everything* the user owns.
 *
 * ## Timing
 *
 * `deleted` + `$afterCommit`, for the reasons in {@see ResumeObserver}. This is
 * the observer where that matters most: a `deleting` hook would wipe the prefix
 * and then, if the `DELETE` failed, leave a live account with every document
 * gone. Storage failures are logged and swallowed inside
 * {@see S3StorageService::deleteForOwner()}, so an S3 outage cannot block an
 * account deletion — which for a user exercising a delete-my-data request is the
 * behaviour that matters, with the caveat that the operator then has orphaned
 * objects to reap from the `storage.delete_failed` log.
 */
class UserObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly S3StorageService $storage) {}

    public function deleted(User $user): void
    {
        $this->storage->deleteForOwner($user);
    }
}
