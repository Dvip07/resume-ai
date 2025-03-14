<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('resumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Link resume to user
            $table->string('original_filename'); // Store original file name
            $table->string('file_path'); // Store file path
            $table->json('parsed_data')->nullable(); // Store extracted JSON structure (skills, experience, etc.)
            $table->json('job_analysis')->nullable(); // Store AI-based job match analysis
            $table->integer('ats_score')->nullable(); // ATS compatibility score
            $table->boolean('is_optimized')->default(false); // Indicates if the resume has been optimized
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resumes');
    }
};
