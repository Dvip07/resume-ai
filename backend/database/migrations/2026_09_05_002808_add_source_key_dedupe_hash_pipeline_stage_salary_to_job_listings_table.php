<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the discovery/pipeline/salary columns `job_listings` needs before the
 * orchestrator (task 8.7) can persist NormalizedJob rows.
 *
 * Column addition only, in its own migration (Requirement 1C.2) — the original
 * 2025_03_11_083440_create_user_profiles_table.php, which created
 * `job_listings`, is left untouched.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            // Which provider produced the row — equals JobSourceProvider::key()
            // ('adzuna', 'jsearch', 'greenhouse', 'lever', 'llm_search').
            // Distinct from the legacy free-text `api_source`, which was set by
            // hand by the Blade-era code; nullable so pre-existing rows stay
            // valid rather than being mislabelled as coming from a provider.
            // (Requirement 3.1/3.3.)
            $table->string('source_key')->nullable()->after('api_source');

            // Requirement 3.3's heuristic dedupe key: sha256 of the normalized
            // title|company|location fingerprint, so exactly 64 hex chars —
            // sized to that rather than the default 255 so the index stays
            // cheap. Indexed, not unique: the orchestrator decides what a
            // collision means (the same role reposted, or two genuinely
            // different openings that normalize alike — see JobDedupeHasher's
            // documented limits), and a DB-level unique constraint would turn
            // that judgement call into a hard insert failure.
            $table->string('dedupe_hash', 64)->nullable()->after('source_key');

            // Portable `string` + app-level enum rather than a native DB enum,
            // matching the choice made for `resumes.status` (cross-driver
            // compatibility with sqlite in tests). Allowed values live in
            // App\Enums\PipelineStage, which the model casts this to.
            // 'discovered' is the default because that is the stage every row
            // enters at (Requirement 3.3); existing rows predate the pipeline
            // and are backfilled to 'store_only' below so they are never
            // picked up as fresh work by the enrichment/scoring stages.
            $table->string('pipeline_stage')->default('discovered')->after('is_active');

            // Whole units per year, no cents (see NormalizedJob's salary
            // contract). `unsignedInteger` tops out at ~4.29 billion, ample for
            // an annual figure even in low-denomination currencies.
            // Feeds ModelRouterService's salary-based tier selection
            // (Requirement 4.3); nullable because most postings omit pay.
            $table->unsignedInteger('salary_min')->nullable()->after('application_url');
            $table->unsignedInteger('salary_max')->nullable()->after('salary_min');

            // ISO-4217 code, required by NormalizedJob whenever either bound is
            // present. Carried because the tier thresholds do no FX conversion,
            // so an unlabelled figure would be misread.
            $table->char('currency', 3)->nullable()->after('salary_max');

            $table->index('dedupe_hash');
            $table->index('pipeline_stage');

            // The exact dedupe path (Requirement 3.3 prefers the source's own
            // id over the heuristic hash): the orchestrator looks a job up by
            // provider + that provider's external id, which is stored in the
            // existing `api_id` column.
            $table->index(['source_key', 'api_id']);
        });

        // Rows that existed before the pipeline: park them in the terminal
        // 'store_only' stage instead of the 'discovered' column default, which
        // would otherwise queue every legacy row for enrichment and scoring on
        // the next run.
        DB::table('job_listings')->update(['pipeline_stage' => 'store_only']);
    }

    /**
     * Reverse the migrations. Fully reversible (Requirement 1C.3) — it only
     * drops columns this migration added; the data in them is derived
     * (recomputable by re-running discovery), not user-authored.
     */
    public function down(): void
    {
        Schema::table('job_listings', function (Blueprint $table) {
            $table->dropIndex(['source_key', 'api_id']);
            $table->dropIndex(['pipeline_stage']);
            $table->dropIndex(['dedupe_hash']);

            $table->dropColumn([
                'source_key',
                'dedupe_hash',
                'pipeline_stage',
                'salary_min',
                'salary_max',
                'currency',
            ]);
        });
    }
};
