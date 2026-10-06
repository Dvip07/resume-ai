<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make "never overwrite" (Requirement 5.6, task 10.4) an invariant the
     * database enforces, not one the scoring job merely intends.
     *
     * {@see \App\Models\JobScore::nextAttemptNumber()} is a read-then-write:
     * `MAX(attempt_number) + 1`. Two workers scoring the same (listing, user)
     * pair — a double dispatch, or a user re-triggering scoring while the first
     * run is still in flight — both read the same number, and the second insert
     * silently produces a *second* row claiming to be attempt N+1. Nothing is
     * overwritten in the literal sense, but the version history stops being a
     * history: `latestAttempt()` picks one of the two arbitrarily and the other
     * verdict becomes unreachable through the versioning API.
     *
     * Unique index rather than a lock, deliberately. The alternative was
     * `SELECT ... FOR UPDATE` inside a transaction, which fails here for two
     * reasons: there is no existing row to lock on a *first* score, so it would
     * need gap/table-level locking whose semantics differ per engine, and the
     * test suite runs on SQLite where row locking is a no-op — the guarantee
     * would be untestable exactly where it is asserted. A unique constraint
     * holds regardless of which code path or which process inserts, needs no
     * cooperation from callers, and turns the race into a catchable error that
     * {@see \App\Models\JobScore::createNextAttempt()} retries.
     *
     * The plain composite index from the create migration is dropped because
     * this one covers the same columns in the same order — the "latest attempt
     * for this user and job" lookups behind
     * {@see \App\Models\JobScore::scopeForPair()} use its leftmost prefix just
     * as well, so keeping both would only cost write throughput.
     */
    public function up(): void
    {
        Schema::table('job_scores', function (Blueprint $table) {
            // Order matters on MySQL: the plain index is the one InnoDB is
            // currently using to satisfy the `job_listing_id` foreign key, so
            // dropping it first fails with errno 1553. Creating the unique
            // index up front gives the FK another index with the same leftmost
            // column to lean on, after which the plain one is redundant and
            // droppable.
            $table->unique(
                ['job_listing_id', 'user_id', 'attempt_number'],
                'job_scores_listing_user_attempt_unique'
            );

            $table->dropIndex('job_scores_listing_user_attempt_index');
        });
    }

    public function down(): void
    {
        Schema::table('job_scores', function (Blueprint $table) {
            // Mirror of up(): restore the plain index before removing the unique
            // one, so the `job_listing_id` foreign key is never left without a
            // usable index (MySQL errno 1553).
            $table->index(
                ['job_listing_id', 'user_id', 'attempt_number'],
                'job_scores_listing_user_attempt_index'
            );

            $table->dropUnique('job_scores_listing_user_attempt_unique');
        });
    }
};
