<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        'is_optimized'
    ];

    // protected $casts = [
    //     'parsed_data' => 'array',
    //     'job_analysis' => 'array',
    //     'is_optimized' => 'boolean',
    // ];
    /**
     * Get the user that owns the resume.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
