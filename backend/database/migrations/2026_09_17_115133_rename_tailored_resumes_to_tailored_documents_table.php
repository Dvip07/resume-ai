<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repurpose the legacy, never-written `tailored_resumes` table as
     * `tailored_documents` (design.md "New tables"; Requirements 6.5, 1C.2).
     *
     * ## Why a rename instead of create + drop
     *
     * `tailored_resumes` is created by the legacy multi-table migration
     * 2025_03_11_083440_create_user_profiles_table, which Requirement 1C.3
     * says must not be edited now that it has run. A rename keeps that history
     * intact, keeps the `applications` foreign key pointing at a real table at
     * every step, and avoids leaving a dead table behind on databases where the
     * legacy migration already ran. Its two payload columns (`file_path`,
     * `ai_analysis`) have no counterpart in the new schema and are dropped;
     * `user_id`, `job_listing_id` and the timestamps carry over unchanged, FKs
     * and all.
     *
     * ## The `applications` foreign key
     *
     * `applications.tailored_resumes_id` referenced `tailored_resumes(id)`.
     * Neither MySQL nor SQLite lets us drop and re-add that constraint the same
     * way (SQLite cannot drop a foreign key at all), so we lean on what both
     * engines do natively instead:
     *
     *   - MySQL 8 `RENAME TABLE` rewrites the referenced-table name inside every
     *     child FK definition, so the constraint follows the rename.
     *   - SQLite updates `REFERENCES` clauses in other tables on
     *     `ALTER TABLE ... RENAME TO` as long as `PRAGMA foreign_keys` is on,
     *     which Laravel's `foreign_key_constraints` config (default true, and
     *     true under phpunit) guarantees.
     *
     * The column itself is renamed `tailored_resumes_id` → `tailored_resume_id`:
     * the old name's plural was a typo against Laravel's convention, and the
     * singular form matches the `tailored_cover_letter_id` sibling that task
     * 14.1 adds — both being FKs into this same table, a `tailored_document_id`
     * name would have read as "the one document" and invited confusion. Both
     * engines update the FK definition on `RENAME COLUMN`, so the rename needs
     * no constraint surgery either. `App\Models\Application::$fillable` is
     * updated to match; nothing else in the codebase referenced the column
     * (`Application` had no relation for it).
     *
     * The FK's leftover constraint *name*
     * (`applications_tailored_resumes_id_foreign` on MySQL) is deliberately
     * left alone — renaming it buys nothing and would need engine-specific SQL.
     */
    public function up(): void
    {
        // Idempotent for a database that somehow already has the new table
        // (e.g. a partially-applied batch), so re-running can't fail on rename.
        if (Schema::hasTable('tailored_documents')) {
            return;
        }

        Schema::rename('tailored_resumes', 'tailored_documents');

        Schema::table('tailored_documents', function (Blueprint $table) {
            // The application this document was produced for. Nullable because
            // tailoring runs before an application row exists (and store_only
            // jobs get tailored documents with no application at all).
            $table->foreignId('application_id')->nullable()->after('job_listing_id')
                ->constrained()->nullOnDelete();

            // Portable strings rather than DB enums, matching pipeline_stage /
            // recommended_action: App\Enums\TailoredDocumentType and
            // TailoredDocumentStatus own the allowed values.
            //
            // Every NOT NULL column added here carries a default, because
            // SQLite rejects a NOT NULL `ADD COLUMN` without one outright (not
            // just on a non-empty table). The defaults are the values a row
            // starts life with anyway: a resume, not yet uploaded, pending.
            $table->string('type')->default('resume')->after('application_id');
            $table->string('template_key')->default('default')->after('type');

            // Object keys, not URLs. '' means "nothing uploaded yet", which is
            // what a `pending` row carries until the render succeeds.
            $table->string('s3_path')->default('')->after('template_key');
            $table->string('tex_source_s3_path')->nullable()->after('s3_path');

            // Which model and which routing tier produced the content, kept for
            // cost attribution and for reproducing a bad generation.
            $table->string('generation_model')->default('')->after('tex_source_s3_path');
            $table->string('generation_tier')->default('')->after('generation_model');

            // App\Services\Tailoring\FabricationReport::flags() verbatim
            // (Requirement 6.6): the findings plus what the guard could check.
            $table->json('fabrication_flags')->nullable()->after('generation_tier');

            $table->string('status')->default('pending')->after('fabrication_flags');
        });

        // Dropped separately: SQLite requires one ALTER per DROP COLUMN, and
        // doing it after the adds keeps the table valid at every step.
        Schema::table('tailored_documents', function (Blueprint $table) {
            $table->dropColumn(['file_path', 'ai_analysis']);
        });

        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('tailored_resumes_id', 'tailored_resume_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Structurally reversible: the table goes back to its legacy name and
     * columns, and the `applications` FK column back to `tailored_resumes_id`.
     * Data in the columns this migration introduced is NOT recoverable — the
     * legacy schema has nowhere to put an `s3_path` or `fabrication_flags`, and
     * `file_path`/`ai_analysis` come back empty since no value for them was
     * ever stored. Rolling back after documents have been generated therefore
     * loses the pointers to the rendered PDFs (the S3 objects themselves are
     * untouched); restore from a backup if the rows matter.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tailored_documents')) {
            return;
        }

        Schema::table('applications', function (Blueprint $table) {
            $table->renameColumn('tailored_resume_id', 'tailored_resumes_id');
        });

        Schema::table('tailored_documents', function (Blueprint $table) {
            $table->string('file_path')->default('');
            $table->json('ai_analysis')->nullable();
        });

        Schema::table('tailored_documents', function (Blueprint $table) {
            // The FK column has to go through dropConstrainedColumn so MySQL
            // drops the constraint before the column; on SQLite the constraint
            // lives in the table definition and goes with it.
            $table->dropConstrainedForeignId('application_id');

            $table->dropColumn([
                'type',
                'template_key',
                's3_path',
                'tex_source_s3_path',
                'generation_model',
                'generation_tier',
                'fabrication_flags',
                'status',
            ]);
        });

        Schema::rename('tailored_documents', 'tailored_resumes');
    }
};
