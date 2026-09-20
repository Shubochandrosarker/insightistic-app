<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\Http;
use Symfony\Component\DomCrawler\Crawler;

/**
 * URL discovery from XML sitemaps. Candidates: robots "Sitemap:" lines plus
 * the conventional /sitemap.xml and /sitemap_index.xml. Caps keep a hostile
 * sitemap from exploding the run:
 *
 * - at most 3 candidate sitemaps fetched
 * - one recursion level into <sitemapindex> (at most 5 child sitemaps)
 * - at most 2000 URLs per sitemap document
 * - results deduplicated and restricted to the site's own host
 */
class SitemapDiscovery
{
    public const MAX_SITEMAPS = 3;

    public const MAX_CHILD_SITEMAPS = 5;

    public const MAX_URLS_PER_SITEMAP = 2000;

    /**
     * @param  string  $baseUrl   scheme://host base of the site
     * @param  list<string>  $robotsSitemaps  sitemap URLs declared in robots.txt
     * @param  string  $userAgent
     * @return list<string> absolute same-host URLs
     */
    public function discover(string $baseUrl, array $robotsSitemaps = [], string $userAgent = 'InsightisticBot/1.0 (+https://insightistic.com/bot)'): array
    {
        $host = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));

        if ($host === '') {
            return [];
        }

        $candidates = [];
        foreach (array_merge($robotsSitemaps, [
            rtrim($baseUrl, '/').'/sitemap.xml',
            rtrim($baseUrl, '/').'/sitemap_index.xml',
        ]) as $url) {
            if (count($candidates) >= self::MAX_SITEMAPS) {
                break;
            }
            $candidates[trim((string) $url)] = true;
        }

        $urls = [];

        foreach (array_keys($candidates) as $candidate) {
            $locs = $this->fetchSitemap($candidate, $userAgent, depth: 0);

            foreach ($locs as $loc) {
                if (count($urls) >= self::MAX_URLS_PER_SITEMAP * self::MAX_SITEMAPS) {
                    break 2; // hard overall stop as well
                }
                if ($this->sameHost($loc, $host)) {
                    $urls[$this->key($loc)] = $loc;
                }
            }
        }

        return array_values($urls);
    }

    /**
     * Fetch one sitemap document and return its page URLs (recursing once
     * through sitemap indexes). Parse failures yield nothing — a broken
     * sitemap must not fail the run.
     *
     * @return list<string>
     */
    private function fetchSitemap(string $url, string $userAgent, int $depth): array
    {
        try {
            $response = Http::withHeaders(['User-Agent' => $userAgent])
                ->timeout(10)
                ->get($url);
        } catch (\Throwable) {
            return [];
        }

        if ($response->status() !== 200) {
            return [];
        }

        try {
            $crawler = new Crawler((string) $response->body());

            // <loc> elements under <sitemap> = child sitemaps of an index;
            // under <url> = pages. local-name() keeps this namespace-agnostic.
            if ($crawler->filterXPath('//*[local-name()="sitemapindex"]')->count() > 0 && $depth === 0) {
                $children = [];
                foreach ($crawler->filterXPath('//*[local-name()="sitemap"]/*[local-name()="loc"]') as $node) {
                    $children[] = trim($node->textContent);
                }

                $urls = [];
                foreach (array_slice(array_unique($children), 0, self::MAX_CHILD_SITEMAPS) as $child) {
                    foreach ($this->fetchSitemap($child, $userAgent, depth: $depth + 1) as $loc) {
                        $urls[] = $loc;
                        if (count($urls) >= self::MAX_URLS_PER_SITEMAP) {
                            return $urls;
                        }
                    }
                }

                return $urls;
            }

            $urls = [];
            foreach ($crawler->filterXPath('//*[local-name()="url"]/*[local-name()="loc"]') as $node) {
                $urls[] = trim($node->textContent);
                if (count($urls) >= self::MAX_URLS_PER_SITEMAP) {
                    break;
                }
            }

            return $urls;
        } catch (\Throwable) {
            return []; // HTML 404 page, malformed XML, ...
        }
    }

    private function sameHost(string $url, string $host): bool
    {
        $urlHost = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return $urlHost !== '' && $urlHost === $host;
    }

    /** Dedupe key: lowercase host+path, query kept verbatim. */
    private function key(string $url): string
    {
        $parts = parse_url(trim($url));

        return strtolower(($parts['host'] ?? '').($parts['path'] ?? '/')).'?'.($parts['query'] ?? '');
    }
}
