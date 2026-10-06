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
        Schema::table('resumes', function (Blueprint $table) {
            // Which filesystem disk the resume's original file lives on.
            // Design.md specifies 's3' as the target default going forward
            // (Requirement 8.1), but existing rows created before this
            // column existed were stored on the local 'public' disk — the
            // consolidate_resume_uploads_into_resumes_table migration that
            // runs immediately after this one backfills the correct value
            // for those pre-existing rows.
            $table->string('storage_disk')->default('s3')->after('file_path');

            // Portable status column: plain `string` + app-level validation
            // rather than a native DB `enum`, so this stays cross-driver
            // compatible (MySQL's ENUM type isn't shared by sqlite/pgsql,
            // and Laravel apps are frequently tested against sqlite — see
            // the commented-out DB_CONNECTION override in phpunit.xml).
            // Allowed values: uploaded, parsing, parsed, failed
            // (Requirement 2.4 / task 4.2 wires the actual transitions).
            $table->string('status')->default('uploaded')->after('storage_disk');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn(['storage_disk', 'status']);
        });
    }
};
