<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The per-user automation consent and throttle record (Requirements 9.1,
     * 9.3, 9.7), read by the scoring job before it branches a highly-rated job
     * into the tailoring/apply pipeline (Requirement 5.2).
     *
     * Own migration, generated with `artisan make:model UserAutomationSetting
     * -m`, per the one-table-per-migration convention of Requirement 1C.
     *
     * `user_id` is unique: automation settings are a 1:1 extension of the user,
     * so the row is the single answer to "may we apply on this person's
     * behalf?" rather than a history. The absence of a row is meaningful too —
     * it means "never configured", which the model resolves to the
     * conservative config defaults (everything off).
     *
     * Every automation flag defaults to off/false. Submitting an application
     * in someone's name is not a reasonable thing to infer from silence, so
     * opting in is always an explicit act.
     */
    public function up(): void
    {
        Schema::create('user_automation_settings', function (Blueprint $table) {
            $table->id();

            // Unique + cascade: one settings row per user, deleted with them.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // The global auto-apply switch of Requirement 9.1. Off by default.
            $table->boolean('auto_apply_enabled')->default(false);

            // Minimum star rating that qualifies a job for auto-apply, a
            // per-user override of config('scoring.auto_apply_star_threshold').
            // Tiny int because the rating scale is 1-5.
            $table->unsignedTinyInteger('auto_apply_star_threshold')->default(4);

            // Requirement 9.3: LinkedIn Easy Apply carries ToS and
            // account-restriction risk, so it is a separate opt-in that the
            // general toggle above does not imply.
            $table->boolean('linkedin_auto_apply_opt_in')->default(false);

            // Requirement 9.7: per-day submission caps. LinkedIn gets its own,
            // lower cap because it is the platform most likely to act on
            // automated activity.
            $table->unsignedInteger('daily_apply_cap')->default(20);
            $table->unsignedInteger('linkedin_daily_apply_cap')->default(5);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_automation_settings');
    }
};
