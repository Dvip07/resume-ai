<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the auto-apply evidence columns `applications` needs before the apply
 * stage (task 15) can record what it did (Requirement 9.5): which tailored
 * cover letter was submitted, which adapter drove the submission, and the
 * step-by-step log/screenshot/error evidence from the attempt.
 *
 * Column addition only, in its own migration (Requirement 1C.2) — the original
 * 2025_03_11_083440_create_user_profiles_table.php, which created
 * `applications`, is left untouched.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // Sibling of `tailored_resume_id` (renamed to the singular by
            // 2026_09_17_115133 precisely so this pair reads alike), pointing at
            // the `cover_letter` row in `tailored_documents`. Nullable because
            // most postings need no cover letter (CoverLetterRequirement) and
            // because the application row can exist before tailoring finishes.
            // nullOnDelete rather than the legacy resume FK's cascade: losing
            // the document should not erase the record that we applied.
            $table->foreignId('tailored_cover_letter_id')->nullable()->after('tailored_resume_id')
                ->constrained('tailored_documents')->nullOnDelete();

            // ApplyAdapter::key()-style identifier of the adapter that ran
            // ('greenhouse', 'lever', 'workday', 'linkedin_easy_apply').
            // Nullable: manually-tracked applications (Requirement 9.8) and
            // legacy rows never went through an adapter, and a placeholder
            // string would misrepresent them as automated.
            $table->string('apply_adapter_used')->nullable()->after('response_status');

            // The attempt's evidence trail — ordered steps taken, screenshot
            // object keys, and the failure reason when the attempt ended
            // `failed`/`needs_review`. JSON rather than columns because its
            // shape is per-adapter and only ever read back whole by the UI when
            // a human reviews an attempt. Kept separate from the existing
            // free-form `metadata`, which the Blade-era code already writes.
            $table->json('automation_log')->nullable()->after('metadata');
        });
    }

    /**
     * Reverse the migrations. Fully reversible in structure; the automation
     * evidence itself is not recoverable after a rollback (nothing in the
     * legacy schema holds it, and screenshots referenced by the log stay in S3
     * untouched). Restore from a backup if past attempts matter.
     */
    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            // dropConstrainedForeignId so MySQL drops the FK before the column;
            // on SQLite the constraint lives in the table definition and goes
            // with the rebuild.
            $table->dropConstrainedForeignId('tailored_cover_letter_id');

            $table->dropColumn(['apply_adapter_used', 'automation_log']);
        });
    }
};
