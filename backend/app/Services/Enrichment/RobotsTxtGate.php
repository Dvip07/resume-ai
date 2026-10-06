<?php

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "May we fetch this URL?", answered from the target host's `robots.txt`
 * (Requirement 3.4c, task 9.1).
 *
 * Enrichment reads pages the platform does not own, so the fetch is gated on the
 * host's published policy rather than on whether the request happens to succeed.
 * A disallowed URL is skipped and logged; it is never retried through the
 * browser fallback either, since the refusal is about the crawler, not the
 * transport.
 *
 * Caching is per scheme+host+user-agent and is the reason this is a separate
 * class: one discovery run commonly produces many postings on the same host
 * (an ATS domain, a single careers site), and fetching `robots.txt` once per
 * posting would be both slow and rude. The negative/unreachable case gets its
 * own short TTL so a momentary outage doesn't bar a host for a day.
 *
 * The parser implements the parts of the de-facto standard that real robots
 * files use: user-agent grouping with `*` fallback, `Allow`/`Disallow` with `*`
 * wildcards and `$` end-anchors, and longest-match-wins precedence with ties
 * going to `Allow`. Crawl-delay, sitemaps and host directives are ignored — this
 * gate answers one question and enrichment fetches one page per job, so rate
 * directives have nothing to pace.
 */
class RobotsTxtGate
{
    /**
     * True when `$url` may be fetched.
     *
     * Fails *closed* on an unreadable robots.txt (configurable): a policy we
     * could not read is not a policy we may ignore. A 404 is not an error — no
     * robots.txt means no restrictions.
     */
    public function allows(string $url): bool
    {
        if (! (bool) config('enrichment.robots.enabled', true)) {
            return true;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            // Not a fetchable absolute URL; the caller rejects it for other
            // reasons, and there is no host whose policy could apply.
            return true;
        }

        $policy = $this->policyFor($parts);

        if ($policy['unreachable'] ?? false) {
            return (bool) config('enrichment.robots.allow_on_unreachable', false);
        }

        return $this->pathAllowed($policy['rules'], $this->pathWithQuery($parts));
    }

    /** The reason string recorded on a refusal, for logs and `needs_review`. */
    public function reasonFor(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return "robots.txt for {$host} disallows this path for ".$this->userAgentToken();
    }

    /**
     * Cached, parsed rules for one origin.
     *
     * @param array<string, mixed> $parts
     *
     * @return array{rules: list<array{allow: bool, pattern: string}>, unreachable: bool}
     */
    private function policyFor(array $parts): array
    {
        $origin = $this->origin($parts);
        $key = 'enrichment:robots:'.sha1($origin.'|'.$this->userAgentToken());

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $policy = $this->load($origin);

        Cache::put($key, $policy, $policy['unreachable']
            ? (int) config('enrichment.robots.error_cache_ttl', 900)
            : (int) config('enrichment.robots.cache_ttl', 86400));

        return $policy;
    }

    /**
     * @return array{rules: list<array{allow: bool, pattern: string}>, unreachable: bool}
     */
    private function load(string $origin): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => (string) config('enrichment.user_agent')])
                ->timeout((int) config('enrichment.robots.timeout', 8))
                ->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($origin.'/robots.txt');
        } catch (Throwable $e) {
            Log::warning('Could not read robots.txt.', ['origin' => $origin, 'reason' => $e->getMessage()]);

            return ['rules' => [], 'unreachable' => true];
        }

        // "No policy published" — the overwhelmingly common case for company
        // career subdomains, and an explicit allow under the standard.
        if ($response->status() === 404 || $response->status() === 410) {
            return ['rules' => [], 'unreachable' => false];
        }

        if (! $response->successful()) {
            Log::warning('robots.txt returned an error status.', [
                'origin' => $origin,
                'status' => $response->status(),
            ]);

            return ['rules' => [], 'unreachable' => true];
        }

        return ['rules' => $this->parse($response->body()), 'unreachable' => false];
    }

    /**
     * Extract the rule group that applies to our user agent.
     *
     * Groups are keyed by the agent token they were declared for. Consecutive
     * `User-agent` lines share one group, which is how robots files address
     * several crawlers at once. The most specific matching token wins (longest
     * token that our agent string starts with), falling back to `*`; a file with
     * neither leaves us unrestricted.
     *
     * @return list<array{allow: bool, pattern: string}>
     */
    private function parse(string $body): array
    {
        /** @var array<string, list<array{allow: bool, pattern: string}>> $groups */
        $groups = [];
        $currentAgents = [];
        $collectingAgents = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim(explode('#', $line, 2)[0]);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = explode(':', $line, 2);
            $field = strtolower(trim($field));
            $value = trim($value);

            if ($field === 'user-agent') {
                if (! $collectingAgents) {
                    $currentAgents = [];
                    $collectingAgents = true;
                }

                $agent = strtolower($value);

                if ($agent !== '') {
                    $currentAgents[] = $agent;
                    $groups[$agent] ??= [];
                }

                continue;
            }

            if ($field !== 'allow' && $field !== 'disallow') {
                continue;
            }

            $collectingAgents = false;

            // A rule before any User-agent line is malformed; some files do it
            // anyway, and treating it as a global rule is the charitable read.
            if ($currentAgents === []) {
                $currentAgents = ['*'];
                $groups['*'] ??= [];
            }

            // `Disallow:` with an empty value is the documented way to say
            // "everything is allowed", so it contributes no rule.
            if ($field === 'disallow' && $value === '') {
                continue;
            }

            foreach ($currentAgents as $agent) {
                $groups[$agent][] = ['allow' => $field === 'allow', 'pattern' => $value];
            }
        }

        return $groups[$this->bestAgentMatch(array_keys($groups))] ?? [];
    }

    /**
     * @param list<string> $agents
     */
    private function bestAgentMatch(array $agents): string
    {
        $us = strtolower($this->userAgentToken());
        $best = '*';

        foreach ($agents as $agent) {
            if ($agent === '*' || ! str_starts_with($us, $agent)) {
                continue;
            }

            if (strlen($agent) > strlen($best) || $best === '*') {
                $best = $agent;
            }
        }

        return $best;
    }

    /**
     * Longest-matching rule wins; an equal-length `Allow` beats a `Disallow`,
     * which is what lets a file say "Disallow: /jobs" then "Allow: /jobs/view".
     * No matching rule means allowed.
     *
     * @param list<array{allow: bool, pattern: string}> $rules
     */
    private function pathAllowed(array $rules, string $path): bool
    {
        $bestLength = -1;
        $allowed = true;

        foreach ($rules as $rule) {
            if (! $this->patternMatches($rule['pattern'], $path)) {
                continue;
            }

            $length = strlen($rule['pattern']);

            if ($length > $bestLength || ($length === $bestLength && $rule['allow'])) {
                $bestLength = $length;
                $allowed = $rule['allow'];
            }
        }

        return $allowed;
    }

    /**
     * robots.txt path matching: a plain prefix test, extended with `*` for any
     * run of characters and a trailing `$` to anchor the end.
     */
    private function patternMatches(string $pattern, string $path): bool
    {
        if ($pattern === '') {
            return false;
        }

        $anchored = str_ends_with($pattern, '$');

        if ($anchored) {
            $pattern = substr($pattern, 0, -1);
        }

        if (! str_contains($pattern, '*')) {
            return $anchored
                ? $path === $pattern
                : str_starts_with($path, $pattern);
        }

        $regex = implode('.*', array_map(
            static fn (string $segment): string => preg_quote($segment, '#'),
            explode('*', $pattern)
        ));

        return (bool) preg_match('#^'.$regex.($anchored ? '$' : '').'#', $path);
    }

    /**
     * The part of the URL rules are written against: path plus query string,
     * since `Disallow: /*?apply=` style rules are real.
     *
     * @param array<string, mixed> $parts
     */
    private function pathWithQuery(array $parts): string
    {
        $path = (string) ($parts['path'] ?? '/');

        if ($path === '') {
            $path = '/';
        }

        return isset($parts['query']) && $parts['query'] !== ''
            ? $path.'?'.$parts['query']
            : $path;
    }

    /**
     * robots.txt is per-origin: scheme, host and non-default port.
     *
     * @param array<string, mixed> $parts
     */
    private function origin(array $parts): string
    {
        $origin = strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host']);

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        return $origin;
    }

    private function userAgentToken(): string
    {
        return (string) config('enrichment.robots_user_agent', 'ResumeAiBot');
    }
}
