<?php

namespace App\Models;

use App\Enums\PipelineStage;
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
        'source_key',
        'dedupe_hash',
        'pipeline_stage',
        'salary_min',
        'salary_max',
        'currency',
    ];

    protected $casts = [
        'parsed_skills' => 'array',
        'posted_at' => 'datetime',
        'is_active' => 'boolean',
        'pipeline_stage' => PipelineStage::class,
        'salary_min' => 'integer',
        'salary_max' => 'integer',
    ];

    /** True when either salary bound is known, i.e. salary-based model tiering applies. */
    public function hasSalary(): bool
    {
        return $this->salary_min !== null || $this->salary_max !== null;
    }

    /**
     * Is the stored description too short to score, i.e. worth an enrichment
     * fetch? (Requirement 3.4)
     *
     * Lives on the model so the stages that ask the question — discovery when
     * it chains to enrichment (task 9.4) and {@see \App\Jobs\EnrichJobDescription}
     * when it decides whether to fetch at all — read one threshold instead of
     * carrying a copy each. Mirrors
     * {@see \App\Services\JobSources\NormalizedJob::needsEnrichment()}, which
     * asks the same of a not-yet-persisted result.
     */
    public function needsEnrichment(): bool
    {
        $minimum = (int) config('job_sources.min_description_length', 400);

        return mb_strlen((string) $this->description) < $minimum;
    }

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