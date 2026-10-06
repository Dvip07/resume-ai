<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-provider, per-day call counters backing the rate limits and daily
     * fetch budget of Requirement 3.6. Read and incremented by
     * App\Services\JobSources\ProviderDailyLimiter before every provider call,
     * so a source that has spent its budget is skipped instead of firing more
     * paid requests.
     *
     * Singular table name (`provider_usage`, not `provider_usages`) to match
     * design.md's data model; App\Models\ProviderUsage pins `$table`.
     *
     * One row per (provider, subject, day). The unique index is what makes the
     * counter safe: concurrent runs race on the insert and the loser falls back
     * to an atomic increment of the existing row rather than creating a second
     * counter that would double the allowance.
     */
    public function up(): void
    {
        Schema::create('provider_usage', function (Blueprint $table) {
            $table->id();

            // Matches JobSourceProvider::key() / job_listings.source_key.
            $table->string('provider_key');

            // Whose budget this is. Nullable for platform-wide counters (a
            // shared API quota is not per-user), and nullOnDelete rather than
            // cascade so deleting a user cannot free up today's spent quota.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('call_count')->default(0);

            // The day the counter covers, in the app timezone. A date (not a
            // rolling window) because the limit is a daily budget: it resets at
            // midnight, and a fresh day simply has no row yet.
            $table->date('window_date');

            $table->timestamps();

            $table->unique(['provider_key', 'user_id', 'window_date'], 'provider_usage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_usage');
    }
};
