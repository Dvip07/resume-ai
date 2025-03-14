<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;


class JobListing extends Model
{
    use HasFactory;

    protected $table = 'job_listings';

    protected $fillable = [
        'api_id',
        'title',
        'company',
        'location',
        'description',
        'api_source',
        'posted_at',
        'is_active',
        'parsed_skills',
        'application_url',
    ];
    protected $casts = ['parsed_skills' => 'array'];

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }
}


// Schema::create('job_listings', function (Blueprint $table) {
//     $table->id();
//     $table->string('api_id')->nullable();
//     $table->string('title');
//     $table->string('company');
//     $table->string('location');
//     $table->text('description');
//     $table->string('api_source'); // LinkedIn, Indeed, etc.
//     $table->timestamp('posted_at')->nullable(); // Date posted
//     $table->boolean('is_active')->default(true);
//     $table->json('parsed_skills'); // Extracted skills from description
//     $table->string('application_url');
//     $table->timestamps();
    
//     $table->index(['title', 'company']);
// });