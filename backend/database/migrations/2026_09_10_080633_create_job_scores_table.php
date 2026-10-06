<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit trail for the two-prompt scoring pipeline (Requirement 5.4):
     * one row per scoring attempt, holding the fit analysis from prompt 1, the
     * rating/rationale/decision from prompt 2, and both prompts' raw output so
     * a result can be reviewed without re-paying for the models.
     *
     * Own migration, generated with `artisan make:model JobScore -m`, per the
     * one-table-per-migration convention of Requirement 1C.
     *
     * Rows are versioned by `attempt_number` and never overwritten
     * (Requirement 5.6): re-scoring a listing inserts attempt N+1, so the
     * earlier decision stays readable. That's why there is no unique index on
     * (job_listing_id, user_id) — only a plain composite index for "latest
     * attempt for this user and job" lookups. (A later migration makes that
     * composite index unique once `attempt_number` is included, so a race
     * cannot mint two rows with the same attempt number; the pair itself stays
     * non-unique.)
     */
    public function up(): void
    {
        Schema::create('job_scores', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete both ways: a score is meaningless without the
            // listing it judged or the profile it was judged against.
            $table->foreignId('job_listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // 1 for the first scoring run, incremented per re-score.
            $table->unsignedInteger('attempt_number')->default(1);

            // Prompt 1: parsed strengths/gaps/keyword coverage.
            $table->json('fit_analysis');

            // Prompt 2: 1-5 rating plus its reasoning and the branch it implies.
            $table->unsignedTinyInteger('stars');
            $table->text('rationale');

            // Portable string rather than a DB enum (matches pipeline_stage);
            // App\Enums\RecommendedAction owns the allowed values.
            $table->string('recommended_action');

            // Unparsed model responses, kept verbatim for auditing and for
            // debugging parse failures (Requirement 5.5).
            $table->text('raw_prompt_1_output');
            $table->text('raw_prompt_2_output');

            $table->timestamps();

            $table->index(['job_listing_id', 'user_id', 'attempt_number'], 'job_scores_listing_user_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_scores');
    }
};
