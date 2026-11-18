<?php

namespace App\Services\Copilot;

use App\Models\Site;
use App\Services\Knowledge\SafeUrlFetcher;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only, SSRF-guarded audit of the live public site attached to a tenant.
 *
 * This is intentionally small and evidence-first: JOLLY checks the homepage,
 * robots.txt, sitemap.xml and up to two same-host internal pages. It never
 * submits forms, executes JavaScript, follows external links as audit targets,
 * or treats page copy as instructions.
 */
class LiveWebAuditor
{
    private const CACHE_MINUTES = 2;
    private const MAX_SAMPLE_PAGES = 2;

    public function __construct(private SafeUrlFetcher $fetcher) {}

    /** @return array{has_data: bool, evidence: array<int,array>, raw: array} */
    public function audit(Site $site): array
    {
        $target = $this->siteUrl($site);
        $cacheKey = 'copilot:live-web-audit:'.(int) $site->id.':'.sha1((string) $target);

        return Cache::remember($cacheKey, now()->addMinutes(self::CACHE_MINUTES), fn () => $this->run($target));
    }

    /** @return array{has_data: bool, evidence: array<int,array>, raw: array} */
    private function run(?string $target): array
    {
        if ($target === null) {
            return [
                'has_data' => false,
                'evidence' => [],
                'raw' => ['status' => 'unavailable', 'reason' => 'The site has no valid public URL.'],
            ];
        }

        $targetHost = $this->host($target);
        $rootAttempt = $this->attempt($target);
        $root = $rootAttempt['response'];
        $origin = $this->origin($root['final_url'] ?? null) ?: $this->origin($target);
        $auditHost = $this->host($root['final_url'] ?? null) ?: $targetHost;
        $evidence = [];

        if ($root !== null && $auditHost !== null && $this->sameHost($targetHost, $auditHost)) {
            $page = $this->parsePage($root['body'], $root['final_url'], (string) ($root['content_type'] ?? ''));
            $evidence[] = $this->evidence('Live homepage', 'HTTP '.$root['status'].' · '.$root['final_url'], $root['final_url']);
            $evidence[] = $this->evidence('Page title', $page['title'] !== '' ? $page['title'] : 'Missing', $root['final_url']);
            $evidence[] = $this->evidence('Meta description', $page['description'] !== '' ? 'Present' : 'Missing', $root['final_url']);
            $evidence[] = $this->evidence('Canonical link', $page['canonical'] !== '' ? $page['canonical'] : 'Missing', $root['final_url']);
            $evidence[] = $this->evidence('H1 headings', (string) $page['h1_count'], $root['final_url']);
            $evidence[] = $this->evidence('Same-host links discovered', (string) count($page['internal_links']), $root['final_url']);
        } elseif ($root !== null) {
            $evidence[] = $this->evidence('Live homepage', 'The final redirect left the connected host; content was not analyzed.', $root['final_url'] ?? $target);
        } else {
            $evidence[] = $this->evidence('Live homepage', 'Fetch failed: '.($rootAttempt['error'] ?? 'unknown error'), $target);
        }

        $robotsUrl = $origin ? rtrim($origin, '/').'/robots.txt' : null;
        $robotsAttempt = $robotsUrl ? $this->attempt($robotsUrl) : ['response' => null, 'error' => 'No site origin'];
        $robots = $robotsAttempt['response'];
        $robotsSummary = null;
        if ($robots !== null && $this->sameHost($auditHost, $this->host($robots['final_url']))) {
            $robotsSummary = $this->parseRobots($robots['body']);
            $evidence[] = $this->evidence(
                'robots.txt',
                sprintf('%d Sitemap directive(s), %d Disallow rule(s)', count($robotsSummary['sitemaps']), $robotsSummary['disallow_count']),
                $robots['final_url'],
            );
        } else {
            $evidence[] = $this->evidence('robots.txt', 'Not available: '.($robotsAttempt['error'] ?? 'cross-host redirect'), $robotsUrl);
        }

        $sitemapCandidate = $robotsSummary['sitemaps'][0] ?? ($origin ? rtrim($origin, '/').'/sitemap.xml' : null);
        $sitemapAttempt = $sitemapCandidate ? $this->attempt($sitemapCandidate) : ['response' => null, 'error' => 'No sitemap URL'];
        $sitemap = $sitemapAttempt['response'];
        $sitemapSummary = null;
        if ($sitemap !== null && $this->sameHost($auditHost, $this->host($sitemap['final_url']))) {
            $sitemapSummary = $this->parseSitemap($sitemap['body']);
            $evidence[] = $this->evidence(
                'Sitemap',
                sprintf('%d URL entr%s discovered (%s)', $sitemapSummary['url_count'], $sitemapSummary['url_count'] === 1 ? 'y' : 'ies', $sitemapSummary['kind']),
                $sitemap['final_url'],
            );
        } else {
            $evidence[] = $this->evidence('Sitemap', 'Not available: '.($sitemapAttempt['error'] ?? 'cross-host redirect'), $sitemapCandidate);
        }

        $samples = [];
        $sampleUrls = $root !== null && isset($page['internal_links']) ? $page['internal_links'] : [];
        foreach (array_slice($sampleUrls, 0, self::MAX_SAMPLE_PAGES) as $sampleUrl) {
            $attempt = $this->attempt($sampleUrl);
            $response = $attempt['response'];
            if ($response === null || ! $this->sameHost($auditHost, $this->host($response['final_url'] ?? null))) {
                $samples[] = ['url' => $sampleUrl, 'status' => 'unavailable', 'error' => $attempt['error'] ?? 'cross-host redirect'];
                continue;
            }

            $sample = $this->parsePage($response['body'], $response['final_url'], (string) ($response['content_type'] ?? ''));
            $samples[] = [
                'url' => $response['final_url'],
                'status' => $response['status'],
                'title' => $sample['title'],
                'description_present' => $sample['description'] !== '',
                'h1_count' => $sample['h1_count'],
                'internal_link_count' => count($sample['internal_links']),
            ];
            $evidence[] = $this->evidence(
                'Sample page: '.($sample['title'] !== '' ? $sample['title'] : $response['final_url']),
                sprintf('HTTP %d · %d H1 · meta description %s', $response['status'], $sample['h1_count'], $sample['description'] !== '' ? 'present' : 'missing'),
                $response['final_url'],
            );
        }

        $raw = [
            'status' => $root !== null ? 'complete' : 'partial',
            'fetched_at' => now()->toIso8601String(),
            'target_url' => $target,
            'homepage' => $root !== null ? [
                'status' => $root['status'],
                'final_url' => $root['final_url'],
                'content_type' => $root['content_type'],
                'title' => $page['title'] ?? '',
                'description' => $page['description'] ?? '',
                'canonical' => $page['canonical'] ?? '',
                'h1_count' => $page['h1_count'] ?? 0,
                'h1_text' => $page['h1_text'] ?? [],
                'internal_links' => $page['internal_links'] ?? [],
            ] : ['error' => $rootAttempt['error'] ?? 'unknown error'],
            'robots' => $robotsSummary ?? ['error' => $robotsAttempt['error'] ?? 'unavailable'],
            'sitemap' => $sitemapSummary ?? ['error' => $sitemapAttempt['error'] ?? 'unavailable'],
            'sample_pages' => $samples,
        ];

        return ['has_data' => $evidence !== [], 'evidence' => $evidence, 'raw' => $raw];
    }

    /** @return array{response: ?array, error: ?string} */
    private function attempt(string $url): array
    {
        try {
            return ['response' => $this->fetcher->fetch($url), 'error' => null];
        } catch (\Throwable $e) {
            return ['response' => null, 'error' => mb_substr($e->getMessage(), 0, 240)];
        }
    }

    private function siteUrl(Site $site): ?string
    {
        $url = trim((string) ($site->canonical_url ?: $site->domain));
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            return null;
        }

        $origin = $this->origin($url);
        $path = (string) ($parts['path'] ?? '/');

        return rtrim((string) $origin, '/').($path === '' ? '/' : '/'.ltrim($path, '/'));
    }

    private function origin(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host'])
            .(isset($parts['port']) ? ':'.(int) $parts['port'] : '');
    }

    private function host(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return $host ? strtolower((string) $host) : null;
    }

    private function sameHost(?string $expected, ?string $actual): bool
    {
        if (! $expected || ! $actual) {
            return false;
        }

        return $this->normalizeHost($expected) === $this->normalizeHost($actual);
    }

    private function normalizeHost(?string $host): ?string
    {
        if (! $host) {
            return null;
        }

        $host = strtolower(rtrim($host, '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @return array{title:string,description:string,canonical:string,h1_count:int,h1_text:array,internal_links:array} */
    private function parsePage(string $html, string $url, string $contentType = ''): array
    {
        $result = ['title' => '', 'description' => '', 'canonical' => '', 'h1_count' => 0, 'h1_text' => [], 'internal_links' => []];
        $isHtmlResponse = $contentType !== '' && (bool) preg_match('/(?:text\/html|application\/xhtml\+xml)\b/i', $contentType);
        $looksLikeHtml = (bool) preg_match('/<html\b|<!doctype\s+html/i', $html);
        if ($html === '' || ($contentType !== '' && ! $isHtmlResponse) || ($contentType === '' && ! $looksLikeHtml)) {
            return $result;
        }

        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return $result;
        }

        $xpath = new \DOMXPath($dom);
        $title = $xpath->query('//title')->item(0);
        $description = $xpath->query('//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="description"]')->item(0);
        $canonical = $xpath->query('//link[contains(concat(" ", translate(@rel,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]')->item(0);
        $h1s = $xpath->query('//h1');
        $result['title'] = trim((string) ($title?->textContent ?? ''));
        $result['description'] = trim((string) ($description?->attributes?->getNamedItem('content')?->nodeValue ?? ''));
        $result['canonical'] = trim((string) ($canonical?->attributes?->getNamedItem('href')?->nodeValue ?? ''));
        $result['h1_count'] = $h1s?->length ?? 0;
        for ($i = 0; $i < $result['h1_count'] && $i < 10; $i++) {
            $result['h1_text'][] = trim((string) $h1s->item($i)?->textContent);
        }

        $baseHost = $this->host($url);
        $links = [];
        foreach ($xpath->query('//a[@href]') as $link) {
            $href = trim((string) $link->attributes?->getNamedItem('href')?->nodeValue);
            $absolute = $this->absoluteUrl($href, $url);
            if ($absolute && $this->sameHost($baseHost, $this->host($absolute))) {
                $links[$absolute] = true;
            }
        }
        $result['internal_links'] = array_keys($links);

        return $result;
    }

    /** @return array{sitemaps:array,disallow_count:int,allow_count:int} */
    private function parseRobots(string $body): array
    {
        $sitemaps = [];
        $disallow = $allow = 0;
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^sitemap:\s*(https?:\/\/\S+)/i', $line, $match)) {
                $sitemaps[] = rtrim($match[1], "\r\n");
            } elseif (preg_match('/^disallow:\s*/i', $line)) {
                $disallow++;
            } elseif (preg_match('/^allow:\s*/i', $line)) {
                $allow++;
            }
        }

        return ['sitemaps' => array_values(array_unique($sitemaps)), 'disallow_count' => $disallow, 'allow_count' => $allow];
    }

    /** @return array{kind:string,url_count:int} */
    private function parseSitemap(string $body): array
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) {
            return ['kind' => 'invalid XML', 'url_count' => 0];
        }

        $root = strtolower((string) $dom->documentElement?->localName);
        $tag = $root === 'sitemapindex' ? 'sitemap' : 'url';

        return ['kind' => $root === 'sitemapindex' ? 'sitemap index' : 'urlset', 'url_count' => $dom->getElementsByTagName($tag)->length];
    }

    private function absoluteUrl(string $href, string $base): ?string
    {
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:mailto|tel|javascript):/i', $href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = (string) parse_url($base, PHP_URL_SCHEME).':'.$href;
        } elseif (! preg_match('#^https?://#i', $href)) {
            $parts = parse_url($base);
            if (! is_array($parts) || empty($parts['host'])) {
                return null;
            }
            $origin = $this->origin($base);
            if (str_starts_with($href, '/')) {
                $href = $origin.$href;
            } else {
                $dir = rtrim(dirname((string) ($parts['path'] ?? '/')), '/');
                $href = $origin.$dir.'/'.$href;
            }
        }
        $parts = parse_url($href);
        if (! is_array($parts) || empty($parts['host']) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)) {
            return null;
        }

        return strtolower((string) $parts['scheme']).'://'.strtolower((string) $parts['host'])
            .(isset($parts['port']) ? ':'.(int) $parts['port'] : '')
            .((string) ($parts['path'] ?? '/') ?: '/');
    }

    private function evidence(string $label, string $value, ?string $url): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'source' => 'live_web',
            'date_range' => 'live',
            'url' => $url,
        ];
    }
}
