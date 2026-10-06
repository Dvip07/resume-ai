<?php

namespace App\Services\JobSources;

use App\Models\User;
use InvalidArgumentException;

/**
 * What a single discovery run asks every provider for (Requirement 3.1).
 *
 * design.md names this type in the `JobSourceProvider` signature without
 * defining it; this is that definition. It is an immutable value object with
 * no behaviour beyond normalizing and validating its own inputs, so providers
 * can read it freely and tests can build one without touching the container.
 *
 * The shape is intentionally the lowest common denominator across very
 * different sources — an aggregator API, a Greenhouse board, and an LLM web
 * search all have to be able to honour it:
 *
 *  - `roles`      the user's target roles / keywords, in priority order.
 *                 Providers that accept only one keyword per call should use
 *                 the first entry (or issue one call per role, budget
 *                 permitting).
 *  - `location`   free-text location as the user typed it ("Toronto, ON",
 *                 "United Kingdom"). Null means "anywhere" — providers that
 *                 require a location should fall back to their configured
 *                 default rather than inventing one.
 *  - `remoteOnly` a hint, not a guarantee. Sources that can't filter on it
 *                 SHOULD ignore it; filtering imperfect results is cheaper
 *                 than dropping a source.
 *  - `limit`      per-provider ceiling on returned jobs, so a fan-out across
 *                 N providers has a bounded cost (Requirement 3.6).
 *  - `userId`     whose run this is. Carried so per-user rate-limit counters
 *                 (`provider_usage.user_id`, task 8.8) and per-user provider
 *                 configuration (e.g. which company slugs to poll) can be
 *                 resolved without a second lookup.
 */
class JobSearchQuery
{
    /** Fallback when neither the caller nor config supplies a limit. */
    public const DEFAULT_LIMIT = 25;

    /** @var list<string> */
    public readonly array $roles;

    /** Trimmed free-text location, or null for "anywhere". */
    public readonly ?string $location;

    /** Per-provider ceiling on returned jobs. Always >= 1. */
    public readonly int $limit;

    /**
     * @param list<string>|string $roles      one or more target roles/keywords
     * @param int|null            $limit      null falls back to config, then DEFAULT_LIMIT
     *
     * @throws InvalidArgumentException when no usable role survives normalization,
     *                                 or the limit is not positive
     */
    public function __construct(
        array|string $roles,
        public readonly int $userId,
        ?string $location = null,
        public readonly bool $remoteOnly = false,
        ?int $limit = null,
    ) {
        $normalizedRoles = array_values(array_filter(
            array_map(
                static fn ($role) => trim((string) $role),
                is_string($roles) ? [$roles] : $roles
            ),
            static fn (string $role) => $role !== ''
        ));

        if ($normalizedRoles === []) {
            throw new InvalidArgumentException(
                'JobSearchQuery needs at least one non-empty role/keyword to search for.'
            );
        }

        $limit ??= (int) config('job_sources.default_limit', self::DEFAULT_LIMIT);

        if ($limit < 1) {
            throw new InvalidArgumentException(
                "JobSearchQuery limit must be at least 1, got {$limit}."
            );
        }

        $this->roles = $normalizedRoles;
        $this->limit = $limit;
        $this->location = $location === null || trim($location) === '' ? null : trim($location);
    }

    /**
     * Build the query for a user. `$roles` still comes from the caller because
     * where roles come from differs by trigger: the scheduled run reads the
     * user's profile, an on-demand run may pass what the user typed.
     *
     * @param list<string>|string $roles
     */
    public static function forUser(
        User $user,
        array|string $roles,
        ?string $location = null,
        bool $remoteOnly = false,
        ?int $limit = null,
    ): self {
        return new self(
            roles: $roles,
            userId: $user->id,
            location: $location,
            remoteOnly: $remoteOnly,
            limit: $limit,
        );
    }

    /**
     * The single keyword to send to sources that accept only one, plus the
     * obvious default for building a search string.
     */
    public function primaryRole(): string
    {
        return $this->roles[0];
    }

    /** All roles as one query string, for sources that take free text. */
    public function keywordString(string $separator = ' '): string
    {
        return implode($separator, $this->roles);
    }

    /** A copy with a different ceiling, e.g. when splitting a budget per role. */
    public function withLimit(int $limit): self
    {
        return new self(
            roles: $this->roles,
            userId: $this->userId,
            location: $this->location,
            remoteOnly: $this->remoteOnly,
            limit: $limit,
        );
    }

    /** A copy narrowed to one role, for sources that must call once per keyword. */
    public function withRoles(array|string $roles): self
    {
        return new self(
            roles: $roles,
            userId: $this->userId,
            location: $this->location,
            remoteOnly: $this->remoteOnly,
            limit: $this->limit,
        );
    }
}
