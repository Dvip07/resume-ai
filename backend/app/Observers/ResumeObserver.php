<?php

namespace App\Observers;

use App\Models\Resume;
use App\Services\Storage\S3StorageService;

/**
 * Removes a resume's original upload from storage once its row is gone
 * (Requirement 8.4).
 *
 * ## `deleted`, not `deleting` — a deliberate deviation from design.md
 *
 * design.md says "wired into model `deleting` events". This observer uses
 * `deleted` instead, and the reason is which failure you get when the two halves
 * disagree:
 *
 * - On `deleting`, the object is destroyed *before* the row. If the `DELETE`
 *   then fails — a foreign key, a lock timeout, a rolled-back surrounding
 *   transaction — the row is still there and its file is not. That is silent
 *   data loss presenting as "my resume download 404s", and nothing in the system
 *   can reconstruct the file.
 * - On `deleted`, the row is gone first. If the storage call fails, the cost is
 *   an orphaned object: real money, per Req 8.4's own stated motivation, but
 *   recoverable at leisure by an operator (see the `storage.delete_failed` log
 *   line in {@see S3StorageService::deleteForOwner()}) and bounded by a bucket
 *   lifecycle rule on `users/`.
 *
 * Orphaned bytes over lost bytes, so `deleted` wins. The `deleting`-hook
 * argument that a throw can abort the DB delete is real but cuts the wrong way
 * here: {@see S3StorageService::deleteForOwner()} deliberately does not throw, so
 * that abort is not available anyway, and a user asking to delete their resume
 * should not be blocked by an S3 outage.
 *
 * `$afterCommit` closes the remaining window: inside a transaction, `deleted`
 * fires before the commit, so a later rollback would resurrect the row after its
 * object had been removed — the same data loss by a slower route. With this flag
 * the delete runs only once the removal is durable, and outside a transaction it
 * behaves exactly as before.
 *
 * ## What this observer does not cover
 *
 * `resumes.user_id` cascades from `users` at the database level. Eloquent events
 * fire only for models deleted through Eloquent, so deleting a user does **not**
 * fire this observer for any of their resumes — the rows vanish inside the
 * database with no PHP involved. That gap is covered by
 * {@see UserObserver}'s prefix delete, not here, and the test
 * `test_a_database_level_cascade_does_not_fire_the_document_observer` in
 * `StorageDeletionTest` pins the behaviour down so nobody has to rediscover it.
 */
class ResumeObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly S3StorageService $storage) {}

    public function deleted(Resume $resume): void
    {
        $this->storage->deleteForOwner($resume);
    }
}
