<?php

namespace App\Observers;

use App\Models\TailoredDocument;
use App\Services\Storage\S3StorageService;

/**
 * Removes a tailored document's PDF and `.tex` source once its row is gone
 * (Requirement 8.4).
 *
 * Timing (`deleted` + `$afterCommit`) and its rationale are shared with
 * {@see ResumeObserver}; read that docblock rather than a paraphrase of it here.
 *
 * ## The cascade this row sits on top of, and what fires
 *
 * `tailored_documents` cascades from both `users` and `job_listings`, and
 * `applications.tailored_resume_id` cascades *from* `tailored_documents`. Two
 * consequences worth stating plainly, because both are easy to assume the other
 * way round:
 *
 * 1. Deleting a user or a job listing wipes `tailored_documents` rows inside the
 *    database. Eloquent model events do not fire for database-level cascades, so
 *    **this observer does not run** in either case. For a user deletion that is
 *    covered by {@see UserObserver}'s per-user prefix delete. For a *job listing*
 *    deletion it is genuinely not covered: every tailored document for that
 *    listing loses its row and its objects are orphaned, across all users. There
 *    is no honest way to describe that as handled. Job listings are not deleted
 *    by any current code path (they are deactivated via `is_active`), which is
 *    why it is documented rather than solved; if a delete path is ever added, it
 *    needs to load and `->delete()` the documents through Eloquent, or do its own
 *    key collection first.
 * 2. Deleting a document through Eloquent runs this observer *and* cascade-
 *    deletes any `applications` row pointing at it. That application deletion is
 *    also invisible to Eloquent — but per
 *    {@see S3StorageService::deleteForOwner()}, an application owns no objects,
 *    so there is nothing missed. The direction that would matter (an application
 *    delete taking out a document's PDF) is explicitly not done: the document row
 *    survives its application via `nullOnDelete`.
 */
class TailoredDocumentObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly S3StorageService $storage) {}

    public function deleted(TailoredDocument $document): void
    {
        $this->storage->deleteForOwner($document);
    }
}
