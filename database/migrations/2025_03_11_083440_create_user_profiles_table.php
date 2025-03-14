<?php

// database/migrations/xxxx_create_user_profiles_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->json('skills'); // { "primary": [], "secondary": [] }
            $table->json('location'); // { "city", "state", "country" }
            $table->string('linkedin_url');
            $table->string('github_url');
            $table->string('portfolio_url');
            $table->json('suggested_roles'); // { "role": "Software Engineer", "years": 2 }
            $table->json('experience'); // { "company", "position", "duration" }
            $table->json('education');
            $table->text('parsed_keywords'); // TSVECTOR alternative for MySQL
            $table->text('resume_text'); // Raw extracted text
            $table->timestamps();
            
            $table->index(['user_id']);
        });

        Schema::create('resume_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('original_resume_path');
            $table->string('parsed_resume_path')->nullable();
            $table->jsonb('parsed_data')->nullable(); // Ollama analysis results
            $table->timestamps();
        });

        Schema::create('job_listings', function (Blueprint $table) {
            $table->id();
            $table->string('api_id')->nullable();
            $table->string('title');
            $table->string('company');
            $table->string('location');
            $table->text('description');
            $table->string('api_source'); // LinkedIn, Indeed, etc.
            $table->timestamp('posted_at')->nullable(); // Date posted
            $table->boolean('is_active')->default(true);
            $table->json('parsed_skills'); // Extracted skills from description
            $table->string('application_url');
            $table->timestamps();
            
            $table->index(['title', 'company']);
        });

        Schema::create('tailored_resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('job_listing_id')->constrained()->onDelete('cascade');  
            $table->string('file_path'); // Path to stored tailored resume
            $table->json('ai_analysis')->nullable(); // AI-generated insights for this resume
            $table->timestamps();
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('job_listing_id')->constrained()->onDelete('cascade');
            $table->foreignId('tailored_resumes_id')->nullable()->constrained('tailored_resumes')->onDelete('cascade'); // Tailored resume
            $table->timestamp('applied_at')->useCurrent(); // Date applied
            // $table->text('custom_resume');
            $table->string('status')->default('applied');
            $table->text('cover_letter')->nullable();
            $table->string('response_status')->nullable();
            // $table->timestamp('applied_at')->useCurrent();
            $table->json('metadata')->nullable();
            $table->timestamps();
            
            $table->index(['user_id', 'job_listing_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('applications');
        Schema::dropIfExists('job_listings');
        Schema::dropIfExists('resume_uploads');
        Schema::dropIfExists('user_profiles');
    }
};
