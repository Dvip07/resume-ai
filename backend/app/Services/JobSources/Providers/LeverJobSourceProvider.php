<?php

namespace App\Services\JobSources\Providers;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Lever public job boards (Requirement 3.1, task 8.4).
 *
 * `GET https://api.lever.co/v0/postings/{slug}?mode=json`
 *
 * The response is a bare JSON array, not an envelope:
 *
 * ```json
 * [{"id": "uuid", "text": "Senior Backend Engineer",
 *   "categories": {"location": "Toronto, ON", "team": "...", "commitment": "..."},
 *   "descriptionPlain": "...", "lists": [{"text": "Requirements", "content": "<ul>..."}],
 *   "additionalPlain": "...", "hostedUrl": "...", "applyUrl": "...",
 *   "createdAt": 1770801262000, "workplaceType": "remote"}]
 * ```
 *
 * Two shape differences from Greenhouse drive most of this class: the JD is
 * split across `descriptionPlain`, the `lists` blocks and `additionalPlain`
 * (so it's reassembled in {@see description()}, otherwise the requirements
 * section — the part scoring cares about most — would be missing), and
 * `createdAt` is a millisecond epoch rather than an ISO string.
 *
 * Which companies get polled comes from `services.lever.companies`; see
 * {@see AtsBoardJobSourceProvider::companies()}.
 */
class LeverJobSourceProvider extends AtsBoardJobSourceProvider
{
    public const KEY = 'lever';

    public function key(): string
    {
        return self::KEY;
    }

    protected function boardUrl(array $company): string
    {
        $baseUrl = rtrim(
            (string) $this->config('base_url', 'https://api.lever.co/v0/postings'),
            '/'
        );

        return $baseUrl.'/'.$company['slug'];
    }

    /**
     * `mode=json` asks for the machine-readable payload; without it the endpoint
     * can answer with rendered HTML.
     *
     * @return array<string, mixed>
     */
    protected function queryParameters(): array
    {
        return ['mode' => 'json'];
    }

    protected function extractPostings(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        // Lever returns a bare list. Some boards (and the grouped `?group=`
        // variant) nest postings one level down instead.
        if (array_key_exists('postings', $payload) && is_array($payload['postings'])) {
            return array_values($payload['postings']);
        }

        return array_values($payload);
    }

    protected function externalId(array $posting): ?string
    {
        $id = $posting['id'] ?? null;

        if (! is_string($id) && ! is_int($id)) {
            return null;
        }

        $id = trim((string) $id);

        return $id === '' ? null : $id;
    }

    /** Lever's posting title lives in `text`, not `title`. */
    protected function title(array $posting): string
    {
        return trim((string) ($posting['text'] ?? ''));
    }

    protected function location(array $posting): string
    {
        $categories = is_array($posting['categories'] ?? null) ? $posting['categories'] : [];

        $location = trim((string) ($categories['location'] ?? ''));

        // Multi-location roles carry the full set in `allLocations`; prefer it
        // when it says more than the single primary location.
        $all = $categories['allLocations'] ?? null;

        if (is_array($all)) {
            $names = [];

            foreach ($all as $name) {
                $name = trim((string) $name);

                if ($name !== '') {
                    $names[] = $name;
                }
            }

            $names = array_values(array_unique($names));

            if (count($names) > 1) {
                return implode(', ', $names);
            }

            if ($location === '' && $names !== []) {
                return $names[0];
            }
        }

        return $location;
    }

    /**
     * Lever does have an explicit flag (`workplaceType`: remote | on-site |
     * hybrid). Fall back to the location text when a board predates it.
     */
    protected function isRemote(array $posting): bool
    {
        $type = mb_strtolower(trim((string) ($posting['workplaceType'] ?? '')));

        if ($type !== '') {
            return $type === 'remote';
        }

        return str_contains(mb_strtolower($this->location($posting)), 'remote');
    }

    /**
     * `hostedUrl` is the public posting page — the right target for enrichment
     * and for the apply adapter, which needs the form. `applyUrl` is the same
     * page's apply route and is only a fallback.
     */
    protected function applicationUrl(array $posting, array $company): string
    {
        foreach (['hostedUrl', 'applyUrl'] as $field) {
            $url = trim((string) ($posting[$field] ?? ''));

            if ($url !== '') {
                return $url;
            }
        }

        return '';
    }

    /**
     * Reassemble the JD from the three places Lever splits it across, in the
     * order the posting page renders them: opening description, then each
     * titled list (requirements, benefits), then the closing text.
     */
    protected function description(array $posting): ?string
    {
        $sections = [];

        $sections[] = $this->plainText($posting['descriptionPlain'] ?? $posting['description'] ?? null);

        $lists = is_array($posting['lists'] ?? null) ? $posting['lists'] : [];

        foreach ($lists as $list) {
            if (! is_array($list)) {
                continue;
            }

            $heading = trim((string) ($list['text'] ?? ''));
            $content = $this->plainText($list['content'] ?? null);

            if ($content === null) {
                continue;
            }

            $sections[] = $heading === '' ? $content : $heading."\n".$content;
        }

        $sections[] = $this->plainText($posting['additionalPlain'] ?? $posting['additional'] ?? null);

        $description = trim(implode("\n\n", array_filter(
            $sections,
            static fn (?string $section) => $section !== null && $section !== ''
        )));

        return $description === '' ? null : $description;
    }

    /** `createdAt` is a millisecond epoch. */
    protected function postedAt(array $posting): ?Carbon
    {
        $createdAt = $posting['createdAt'] ?? null;

        if (is_numeric($createdAt) && (int) $createdAt > 0) {
            try {
                return Carbon::createFromTimestampMsUTC((int) $createdAt);
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
