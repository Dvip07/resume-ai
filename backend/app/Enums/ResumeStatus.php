<?php

namespace App\Enums;

/**
 * Lifecycle of a `resumes` row (Requirement 2.4).
 *
 * uploaded → parsing → parsed
 *                    ↘ failed
 *
 * The DB column is a portable `string` (see
 * 2026_09_03_114432_add_storage_disk_and_status_to_resumes_table), so this
 * enum is the single place the allowed values live: the API controller and
 * AnalyzeResumeJob both reference it instead of raw strings, and the Resume
 * model casts `status` to it.
 */
enum ResumeStatus: string
{
    /** Row created; original file not yet stored. */
    case Uploaded = 'uploaded';

    /** File stored and AnalyzeResumeJob queued/running. */
    case Parsing = 'parsing';

    /** Structured extraction finished and persisted. */
    case Parsed = 'parsed';

    /** Upload or extraction failed; `status_error` carries the reason. */
    case Failed = 'failed';

    /**
     * A resume in this status is done being worked on — nothing further is
     * queued for it, so the frontend can stop polling.
     */
    public function isTerminal(): bool
    {
        return $this === self::Parsed || $this === self::Failed;
    }
}
