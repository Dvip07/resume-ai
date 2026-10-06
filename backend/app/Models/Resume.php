<?php

namespace App\Models;

use App\Enums\ResumeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Resume extends Model
{
    use HasFactory;

    protected $table = 'resumes';

    protected $fillable = [
        'user_id',
        'original_filename',
        'file_path',
        'parsed_data',
        'job_analysis',
        'ats_score',
        'is_optimized',
        'storage_disk',
        'status',
        'status_error',
    ];

    protected $casts = [
        'parsed_data' => 'array',
        'job_analysis' => 'array',
        'is_optimized' => 'boolean',
        'status' => ResumeStatus::class,
    ];

    /**
     * Move the resume to a terminal `parsed` state, clearing any error left
     * over from a previous attempt (Requirement 2.4).
     */
    public function markParsed(): void
    {
        $this->update([
            'status' => ResumeStatus::Parsed,
            'status_error' => null,
        ]);
    }

    /**
     * Move the resume to a terminal `failed` state with a user-facing reason,
     * so the record never stays stuck in `parsing` (Requirement 2.4).
     */
    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => ResumeStatus::Failed,
            'status_error' => Str::limit(trim($reason), 1000),
        ]);
    }

    /**
     * Get the user that owns the resume.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
