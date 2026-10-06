<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    protected $table = 'user_profiles';

    protected $fillable = [
        'user_id',
        'skills',
        'location',
        'linkedin_url',
        'github_url',
        'portfolio_url',
        'suggested_roles',
        'experience',
        'education',
        'parsed_keywords',
        'resume_text',
    ];

    protected $casts = [
        'skills' => 'array',
        'location' => 'array',
        'suggested_roles' => 'array',
        'experience' => 'array',
        'education' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}