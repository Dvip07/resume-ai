<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Backfills every existing `resume_uploads` row into `resumes` (mapping
     * the legacy columns onto the consolidated schema), then drops
     * `resume_uploads`. See Requirements 1.5, 1A.4.
     *
     * Column mapping (see ResumeUploadController::store() / ResumeUpload
     * model for the source shapes being migrated):
     *   - original_resume_path -> resumes.original_filename
     *       (despite the "path" name, ResumeUploadController actually
     *       stores the original client filename string here)
     *   - parsed_resume_path   -> resumes.file_path
     *       (this is the actual on-disk storage path)
     *   - parsed_data          -> resumes.parsed_data (kept as-is; note this
     *       may hold raw extracted PDF text rather than structured JSON for
     *       rows that were never picked up by AnalyzeResumeJob's update)
     *   - storage_disk is set to 'public' (not the new 's3' default) since
     *     every `resume_uploads` file was written via
     *     `$resumeFile->storeAs('resume', $originalFileName, 'public')`.
     *   - status is set to 'parsed' when parsed_data already looks like a
     *     decoded structured array (i.e. AnalyzeResumeJob successfully ran)
     *     and 'uploaded' otherwise, since that's the only signal available
     *     to distinguish the two states after the fact.
     */
    public function up(): void
    {
        if (Schema::hasTable('resume_uploads')) {
            $now = now();

            DB::table('resume_uploads')->orderBy('id')->each(function ($upload) use ($now) {
                // `parsed_data` is cast to `array` on the ResumeUpload model,
                // but here we're reading raw column values via the query
                // builder, so decode manually.
                $decoded = null;
                if (! is_null($upload->parsed_data)) {
                    $decoded = json_decode($upload->parsed_data, true);
                }

                $looksStructured = is_array($decoded) && count($decoded) > 0;

                DB::table('resumes')->insert([
                    'user_id' => $upload->user_id,
                    'original_filename' => $upload->original_resume_path,
                    'file_path' => $upload->parsed_resume_path ?? '',
                    'parsed_data' => $upload->parsed_data,
                    'job_analysis' => null,
                    'ats_score' => null,
                    'is_optimized' => false,
                    'storage_disk' => 'public',
                    'status' => $looksStructured ? 'parsed' : 'uploaded',
                    'created_at' => $upload->created_at ?? $now,
                    'updated_at' => $upload->updated_at ?? $now,
                ]);
            });

            Schema::drop('resume_uploads');
        }
    }

    /**
     * Reverse the migrations.
     *
     * This recreates the `resume_uploads` table schema so the migration is
     * technically reversible, but data restoration is intentionally NOT
     * attempted: once rows are folded into `resumes` there is no reliable
     * way to tell which `resumes` rows originated from `resume_uploads`
     * (both tables share the same shape of data after backfill), so
     * reversing this migration gives you back an empty `resume_uploads`
     * table, not the original rows. If a true rollback with data is ever
     * needed, restore from a database backup taken before this migration
     * ran instead of relying on this down() method.
     */
    public function down(): void
    {
        Schema::create('resume_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('original_resume_path');
            $table->string('parsed_resume_path')->nullable();
            $table->jsonb('parsed_data')->nullable();
            $table->timestamps();
        });
    }
};
