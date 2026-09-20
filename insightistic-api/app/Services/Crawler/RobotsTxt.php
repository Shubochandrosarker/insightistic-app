<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * robots.txt for one host, fetched at most once per run (cached in the
 * Laravel cache store by host). Simple, documented handling:
 *
 * - fetch failure, 404 or 5xx ⇒ allow-all (per roadmap: respect robots by
 *   default, but an unreadable robots.txt must not block a whole crawl)
 * - user-agent groups are matched exactly and case-insensitively; our bot's
 *   group wins, otherwise the '*' group applies
 * - a trailing '*' on a rule is stripped and the remainder prefix-matched
 *   (no interior wildcards — the common WordPress case is enough for MVP)
 * - longest matching prefix wins; on equal length Allow beats Disallow
 */
class RobotsTxt
{
    public const OUR_AGENT = 'insightisticbot';

    /** @var array<string, array{allow: list<string>, disallow: list<string>}> keyed by lowercase agent */
    private array $groups = [];

    /** @var list<string> */
    private array $sitemaps = [];

    /** Crawl-delay in milliseconds as declared, or null. */
    private ?int $crawlDelayMs = null;

    private bool $exists = false;

    public static function forHost(string $baseUrl, string $userAgent): self
    {
        $host = (string) parse_url($baseUrl, PHP_URL_HOST) ?: $baseUrl;
        $key = 'crawl:robots:'.md5($host);

        $state = Cache::get($key);

        if (is_array($state)) {
            return self::fromArray($state);
        }

        $robots = self::fetch($baseUrl, $userAgent);
        Cache::put($key, $robots->toArray(), now()->addMinutes(30));

        return $robots;
    }

    public static function fetch(string $baseUrl, string $userAgent): self
    {
        $parts = parse_url($baseUrl);
        $host = $parts['host'] ?? null;

        if (! $host) {
            return new self;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $url = $scheme.'://'.$host.(isset($parts['port']) ? ':'.$parts['port'] : '').'/robots.txt';

        try {
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(10)
                ->accept('*/*')
                ->get($url);
        } catch (\Throwable) {
            return new self; // network error ⇒ allow-all
        }

        if ($response->status() !== 200) {
            return new self; // 404 / 5xx ⇒ allow-all
        }

        return self::parse((string) $response->body());
    }

    public static function parse(string $body): self
    {
        $robots = new self;
        $robots->exists = true;

        // agents in the group currently being read; consecutive User-agent
        // lines share one group — the first non-UA directive closes it.
        $currentAgents = [];
        $groupOpen = false;

        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $line = trim((string) preg_replace('/#.*$/', '', $line));

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = array_map('trim', explode(':', $line, 2));
            $field = strtolower($field);

            switch ($field) {
                case 'user-agent':
                    if ($groupOpen && $currentAgents !== []) {
                        $currentAgents = []; // a directive was read: new group
                    }
                    $currentAgents[] = strtolower($value);
                    $groupOpen = false;

                    break;

                case 'disallow':
                case 'allow':
                    if ($currentAgents === []) {
                        break;
                    }
                    $groupOpen = true;
                    $prefix = rtrim($value, '*'); // single trailing-* wildcard ⇒ prefix match
                    if ($prefix === '') {
                        break; // "Disallow:" (empty) means disallow nothing
                    }
                    foreach ($currentAgents as $agent) {
                        $robots->groups[$agent][$field][] = $prefix;
                    }

                    break;

                case 'crawl-delay':
                    if ($currentAgents !== [] && is_numeric($value)) {
                        $robots->crawlDelayMs = max(0, (int) round((float) $value * 1000));
                    }

                    break;

                case 'sitemap':
                    if ($value !== '') {
                        $robots->sitemaps[] = $value;
                    }

                    break;
            }
        }

        return $robots;
    }

    /**
     * Longest-prefix match over the applicable group. Allow beats Disallow on
     * equal length; no matching rule ⇒ allowed.
     */
    public function isAllowed(string $pathWithQuery): bool
    {
        $group = $this->groups[self::OUR_AGENT] ?? $this->groups['*'] ?? null;

        if ($group === null) {
            return true;
        }

        $best = null; // ['allow' => bool, 'len' => int]

        foreach (['allow' => true, 'disallow' => false] as $kind => $allowed) {
            foreach ($group[$kind] ?? [] as $prefix) {
                if ($prefix !== '' && str_starts_with($pathWithQuery, $prefix)) {
                    $len = mb_strlen($prefix);
                    // strictly longer wins; equal length prefers Allow
                    if ($best === null || $len > $best['len'] || ($len === $best['len'] && $allowed)) {
                        $best = ['allow' => $allowed, 'len' => $len];
                    }
                }
            }
        }

        return $best === null ? true : $best['allow'];
    }

    /** Declared Crawl-delay in ms (already the matched group's), or null. */
    public function crawlDelayMs(): ?int
    {
        return $this->crawlDelayMs;
    }

    /** @return list<string> */
    public function sitemapUrls(): array
    {
        return array_values(array_unique($this->sitemaps));
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function toArray(): array
    {
        return [
            'groups' => $this->groups,
            'sitemaps' => $this->sitemaps,
            'crawlDelayMs' => $this->crawlDelayMs,
            'exists' => $this->exists,
        ];
    }

    public static function fromArray(array $state): self
    {
        $robots = new self;
        $robots->groups = $state['groups'] ?? [];
        $robots->sitemaps = $state['sitemaps'] ?? [];
        $robots->crawlDelayMs = $state['crawlDelayMs'] ?? null;
        $robots->exists = (bool) ($state['exists'] ?? false);

        return $robots;
    }
}
