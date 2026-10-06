<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cost/usage ledger for every OpenRouter call made through
     * ModelRouterService (Requirement 4.6). One row per *attempt*, not per
     * logical call, because Requirement 4.6 asks for every call to be
     * recorded and Requirement 4.5's fallback walk can make several before one
     * answers — collapsing them would hide exactly the failures we are
     * supposed to be able to diagnose.
     *
     * Append-only, so there is no `updated_at` (design.md lists `created_at`
     * only) and no foreign keys: the related record is polymorphic
     * (`job_listings`, `resumes`, …) and a usage/cost row should survive its
     * subject being deleted rather than disappear from the ledger.
     */
    public function up(): void
    {
        Schema::create('model_usage_logs', function (Blueprint $table) {
            $table->id();

            $table->string('task_type')->index();
            $table->string('model_used');
            $table->string('tier_used');

            // 'success' | 'failure' — App\Enums\ModelUsageOutcome. Kept a
            // portable string rather than a DB enum so adding an outcome later
            // does not need a column rewrite (same call as `resumes.status`).
            $table->string('outcome', 16);

            // Which try inside the fallback walk this was, so a row can be read
            // as "second attempt on the second model" (Requirement 4.5).
            $table->unsignedSmallInteger('attempt')->default(1);

            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);

            // 6 decimal places: per-call OpenRouter costs are routinely
            // fractions of a cent, and rounding them to 2dp would floor most
            // rows to 0.00 and make the ledger useless for cost tracking.
            $table->decimal('estimated_cost_usd', 12, 6)->default(0);

            // Failure diagnostics (Requirement 4.5). Null on success.
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('error')->nullable();

            // related_type + related_id: the job listing / resume this call was
            // made for (Requirement 4.6), nullable because some calls are not
            // about a stored record yet.
            $table->nullableMorphs('related');

            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_usage_logs');
    }
};
