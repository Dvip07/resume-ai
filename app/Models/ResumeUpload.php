<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResumeUpload extends Model
{
    use HasFactory;

    protected $table = 'resume_uploads';

    protected $fillable = [
        'user_id',
        'original_resume_path',
        'parsed_resume_path',
        'parsed_data',        
    ];
    
    protected $casts = ['parsed_data' => 'array'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
