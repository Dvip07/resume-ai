<?php

namespace App\Services\JobSources\Providers;

use Illuminate\Support\Carbon;
use Throwable;

/**
 * Greenhouse public job boards (Requirement 3.1, task 8.4).
 *
 * `GET https://boards-api.greenhouse.io/v1/boards/{slug}/jobs?content=true`
 *
 * ```json
 * {"jobs": [{"id": 4567890, "title": "...", "absolute_url": "...",
 *            "location": {"name": "Toronto, ON"}, "content": "&lt;p&gt;...",
 *            "first_published": "...", "updated_at": "...",
 *            "offices": [...], "departments": [...]}], "meta": {"total": 1}}
 * ```
 *
 * `content=true` is what makes this source worth polling: it returns the full
 * JD inline, so these jobs generally don't need the enrichment stage. The cost
 * is that Greenhouse serves that field as *escaped* HTML (`&lt;p&gt;`), hence
 * the double decode in {@see plainText()}.
 *
 * Which companies get polled comes from `services.greenhouse.companies`; see
 * {@see AtsBoardJobSourceProvider::companies()} for the accepted formats.
 * Everything else — client-side filtering, the limit ceiling, dedupe, per-board
 * error isolation — is in the base class.
 */
class GreenhouseJobSourceProvider extends AtsBoardJobSourceProvider
{
    public const KEY = 'greenhouse';

    public function key(): string
    {
        return self::KEY;
    }

    protected function boardUrl(array $company): string
    {
        $baseUrl = rtrim(
            (string) $this->config('base_url', 'https://boards-api.greenhouse.io/v1/boards'),
            '/'
        );

        return $baseUrl.'/'.$company['slug'].'/jobs';
    }

    /**
     * Ask for the inline JD unless it's been turned off (a board with hundreds
     * of long postings is a big response; without it every job falls through to
     * enrichment instead).
     *
     * @return array<string, mixed>
     */
    protected function queryParameters(): array
    {
        return [
            'content' => $this->config('include_content', true) ? 'true' : 'false',
        ];
    }

    protected function extractPostings(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $jobs = $payload['jobs'] ?? [];

        return is_array($jobs) ? array_values($jobs) : [];
    }

    protected function externalId(array $posting): ?string
    {
        $id = $posting['id'] ?? null;

        if (is_int($id) || is_float($id)) {
            return (string) (int) $id;
        }

        if (! is_string($id) || trim($id) === '') {
            return null;
        }

        return trim($id);
    }

    protected function title(array $posting): string
    {
        return trim((string) ($posting['title'] ?? ''));
    }

    /**
     * `location.name` is the posting's own free text. When it's absent, fall
     * back to joining the office names, which is what Greenhouse shows in that
     * case.
     */
    protected function location(array $posting): string
    {
        $location = trim((string) ($posting['location']['name'] ?? ''));

        if ($location !== '') {
            return $location;
        }

        $offices = is_array($posting['offices'] ?? null) ? $posting['offices'] : [];

        $names = [];

        foreach ($offices as $office) {
            $name = is_array($office) ? trim((string) ($office['name'] ?? '')) : '';

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return implode(', ', array_unique($names));
    }

    /**
     * Greenhouse has no remote flag — remoteness is stated in the location or
     * office name, which is the same string a human reads it from.
     */
    protected function isRemote(array $posting): bool
    {
        return str_contains(mb_strtolower($this->location($posting)), 'remote');
    }

    protected function applicationUrl(array $posting, array $company): string
    {
        return trim((string) ($posting['absolute_url'] ?? ''));
    }

    protected function description(array $posting): ?string
    {
        return $this->plainText($posting['content'] ?? null);
    }

    /**
     * `first_published` is when the role went live; `updated_at` is the only
     * date on older payloads. Junk in either leaves postedAt null rather than
     * failing the posting.
     */
    protected function postedAt(array $posting): ?Carbon
    {
        foreach (['first_published', 'updated_at'] as $field) {
            $value = $posting[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
