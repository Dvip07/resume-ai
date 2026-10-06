<?php

namespace App\Console\Commands;

use App\Jobs\DiscoverJobsForUser;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Console\Command;

/**
 * The scheduled trigger for discovery (Requirements 11.1, 3.1).
 *
 * Dispatches one {@see DiscoverJobsForUser} per candidate user and returns; it
 * runs no provider calls itself, so a slow or dead source can never hold up the
 * scheduler. See `App\Console\Kernel::schedule()` for the cadence.
 *
 * A candidate is any user with a profile — the profile is where the roles and
 * location to search for come from. Users whose profile has no roles yet (no
 * resume parsed) are filtered out here rather than dispatched and no-oped, so
 * an idle system queues nothing.
 */
class DiscoverJobs extends Command
{
    protected $signature = 'jobs:discover
        {--user=* : Restrict the run to these user IDs (repeatable). Defaults to every user with a profile.}
        {--role=* : Search for these roles instead of the profile\'s suggested roles (repeatable).}
        {--location= : Search this location instead of the profile\'s.}
        {--limit= : Per-provider result ceiling for this run.}';

    protected $description = 'Queue a job-discovery run (fan-out across all enabled job sources) for users.';

    public function handle(): int
    {
        $roles = $this->roleOverride();
        $location = $this->option('location') ?: null;
        $limit = $this->limitOption();

        if ($limit !== null && $limit < 1) {
            $this->error('--limit must be at least 1.');

            return self::INVALID;
        }

        $userIds = $this->targetUserIds($roles !== null);

        if ($userIds === []) {
            $this->info('No users to discover jobs for.');

            return self::SUCCESS;
        }

        foreach ($userIds as $userId) {
            DiscoverJobsForUser::dispatch($userId, $roles, $location, $limit);
        }

        $this->info(sprintf(
            'Queued job discovery for %d user%s.',
            count($userIds),
            count($userIds) === 1 ? '' : 's'
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null null when the profile's roles should be used
     */
    private function roleOverride(): ?array
    {
        $roles = array_values(array_filter(
            array_map('trim', (array) $this->option('role')),
            static fn (string $role) => $role !== ''
        ));

        return $roles === [] ? null : $roles;
    }

    private function limitOption(): ?int
    {
        $limit = $this->option('limit');

        return $limit === null || $limit === '' ? null : (int) $limit;
    }

    /**
     * Who to dispatch for.
     *
     * `--user` is honoured as given (an explicit request shouldn't be silently
     * dropped for a missing profile — the job logs why it did nothing). Without
     * it, only users whose profile actually names roles are queued, unless the
     * caller supplied roles for everyone via `--role`.
     *
     * @return list<int>
     */
    private function targetUserIds(bool $rolesOverridden): array
    {
        $explicit = array_values(array_filter(
            array_map('intval', (array) $this->option('user')),
            static fn (int $id) => $id > 0
        ));

        if ($explicit !== []) {
            return array_values(array_unique($explicit));
        }

        $profiles = UserProfile::query()->whereNotNull('user_id');

        if (! $rolesOverridden) {
            // Any non-empty JSON value: '[]', 'null' and '' are all "no roles
            // yet", whatever shape the column was written in.
            $profiles->whereNotNull('suggested_roles')
                ->whereNotIn('suggested_roles', ['', '[]', 'null']);
        }

        return User::query()
            ->whereIn('id', $profiles->select('user_id'))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
