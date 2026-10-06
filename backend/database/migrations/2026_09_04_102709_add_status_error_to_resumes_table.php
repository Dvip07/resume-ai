<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requirement 2.4: when resume parsing or LLM extraction fails, the
     * failure has to be surfaced to the user rather than leaving the record
     * in a silent "processing" state. `status` alone only says *that* it
     * failed, so this column carries *why* — the message the frontend shows
     * next to a `failed` resume.
     *
     * Kept as a nullable `text` (not a fixed-length string) because the
     * reason may be an LLM/HTTP error body excerpt; the application truncates
     * before persisting.
     */
    public function up(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->text('status_error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('resumes', function (Blueprint $table) {
            $table->dropColumn('status_error');
        });
    }
};
