<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    use HasFactory;

    protected $table = 'applications';

    protected $fillable = [
        'user_id',
        'job_listing_id',
        // Renamed from the legacy `tailored_resumes_id` when that table became
        // `tailored_documents` (see the rename migration for why the singular).
        'tailored_resume_id',
        'tailored_cover_letter_id',
        'applied_at',
        'status',
        'cover_letter',
        'response_status',
        'apply_adapter_used',
        'metadata',
        'automation_log',
    ];

    protected $casts = [
        'metadata' => 'array',
        // The apply attempt's evidence trail (Requirement 9.5): steps taken,
        // screenshot keys, failure reason. Read back whole, so a plain array.
        'automation_log' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jobListing(): BelongsTo
    {
        return $this->belongsTo(JobListing::class);
    }

    /**
     * The tailored resume submitted with this application (Requirement 6.5),
     * or null before tailoring has produced one.
     */
    public function tailoredResume(): BelongsTo
    {
        return $this->belongsTo(TailoredDocument::class, 'tailored_resume_id');
    }

    /**
     * The tailored cover letter submitted with this application, or null when
     * the posting needed none / tailoring has not produced one yet.
     */
    public function tailoredCoverLetter(): BelongsTo
    {
        return $this->belongsTo(TailoredDocument::class, 'tailored_cover_letter_id');
    }
}
