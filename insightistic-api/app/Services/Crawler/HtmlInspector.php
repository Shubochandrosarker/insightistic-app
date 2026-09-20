<?php

namespace App\Services\Crawler;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use GuzzleHttp\Psr7\Utils;
use Symfony\Component\DomCrawler\Crawler;

/**
 * On-page inspection of one fetched HTML document. All querying goes through
 * Symfony DomCrawler (no regex HTML parsing). The whole inspection is
 * defensive: malformed HTML or JSON-LD degrades to a partial result with
 * parse_error / schema_error flags — it never throws into the crawl chain.
 *
 * Document loading note: dom-crawler ^8.1 parses HTML exclusively through
 * PHP 8.4's Dom\HTMLDocument. On runtimes below 8.4 that class does not
 * exist, so we parse with libxml's HTML parser (DOMDocument::loadHTML) and
 * hand the document to Crawler's own query engine — same filters, same API.
 * Malformed markup is tolerated by libxml; truly hopeless input still ends
 * in a parse_error result.
 *
 * Returns:
 *   title, meta_description, h1_count, word_count, robots_noindex,
 *   canonical_url, links[] {href, anchor, nofollow}, image_count,
 *   image_missing_alt, schema_types[], schema_error, parse_error, content_hash
 */
class HtmlInspector
{
    /**
     * @param  string  $url     final URL the document was fetched from (base for relativization)
     * @param  string  $html
     * @param  string|null  $xRobotsTag  raw X-Robots-Tag response header, if any
     */
    public function inspect(string $url, string $html, ?string $xRobotsTag = null): array
    {
        $result = [
            'title' => null,
            'meta_description' => null,
            'h1_count' => 0,
            'word_count' => 0,
            'robots_noindex' => $this->headerNoindex($xRobotsTag),
            'canonical_url' => null,
            'links' => [],
            'image_count' => 0,
            'image_missing_alt' => 0,
            'schema_types' => [],
            'schema_error' => false,
            'parse_error' => false,
            'content_hash' => null,
        ];

        if (trim($html) === '') {
            $result['parse_error'] = true;

            return $result;
        }

        try {
            $crawler = self::newCrawler($html, $url);

            // --- title / meta description / robots meta / canonical ------
            $title = $crawler->filter('title');
            $result['title'] = $title->count() > 0
                ? $this->cleanText($title->first()->text(null, true), 500) ?: null
                : null;

            $description = $crawler->filterXPath('//meta['.$this->ciAttr('name', 'description').']');
            $result['meta_description'] = $description->count() > 0
                ? $this->cleanText($description->first()->attr('content') ?? '', 1000) ?: null
                : null;

            foreach ($crawler->filterXPath('//meta['.$this->ciAttr('name', 'robots').']') as $meta) {
                if (str_contains(strtolower((string) $meta->getAttribute('content')), 'noindex')) {
                    $result['robots_noindex'] = true;
                }
            }

            $canonical = $crawler->filterXPath('//link['.$this->ciAttr('rel', 'canonical').']');
            if ($canonical->count() > 0) {
                $href = trim((string) $canonical->first()->attr('href') ?? '');
                $result['canonical_url'] = $href !== '' ? $this->absolute($url, $href) : null;
            }

            // --- JSON-LD structured data ---------------------------------
            // Must run before the word-count strip below removes <script> nodes.
            foreach ($crawler->filterXPath('//script[contains(translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz"),"application/ld+json")]') as $script) {
                $this->collectSchemaTypes((string) $script->textContent, $result);
            }

            // --- headings / content --------------------------------------
            $result['h1_count'] = $crawler->filter('h1')->count();

            foreach ($crawler->filterXPath('//script|//style') as $node) {
                $node->parentNode?->removeChild($node);
            }

            $text = '';
            $body = $crawler->filter('body');
            if ($body->count() > 0) {
                $text = (string) $body->text(null, true);
            } else {
                $text = (string) $crawler->text(null, true);
            }

            $result['word_count'] = $this->countWords($text);
            $result['content_hash'] = sha1((string) preg_replace('/\s+/u', ' ', trim($text)));

            // --- links ----------------------------------------------------
            $seenOnPage = [];
            foreach ($crawler->filter('a[href]') as $anchorNode) {
                $href = $this->absolute($url, trim((string) $anchorNode->getAttribute('href')));

                if ($href === null || isset($seenOnPage[$href])) {
                    continue;
                }
                $seenOnPage[$href] = true;

                $rel = strtolower((string) $anchorNode->getAttribute('rel'));
                $result['links'][] = [
                    'href' => $href,
                    'anchor' => $this->cleanText($anchorNode->textContent, 300) ?: null,
                    'nofollow' => str_contains($rel, 'nofollow'),
                ];
            }

            // --- images ---------------------------------------------------
            foreach ($crawler->filter('img') as $img) {
                $result['image_count']++;
                if (trim((string) $img->getAttribute('alt')) === '') {
                    $result['image_missing_alt']++;
                }
            }
        } catch (\Throwable) {
            $result['parse_error'] = true;
        }

        return $result;
    }

    /**
     * A Crawler over $html with $url as the base for relativization.
     * Native string path on PHP 8.4+ (Dom\HTMLDocument); libxml DOMDocument
     * handed to the same Crawler engine everywhere else (see class docblock).
     */
    private static function newCrawler(string $html, string $url): Crawler
    {
        if (class_exists(\Dom\HTMLDocument::class)) {
            return new Crawler($html, $url);
        }

        $document = new \DOMDocument();
        libxml_use_internal_errors(true); // malformed HTML → recoverable noise, not exceptions
        try {
            $document->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
        } finally {
            libxml_clear_errors();
        }

        return new Crawler($document, $url);
    }

    /**
     * Decode one JSON-LD block, collecting @type values (top-level, inside
     * arrays and inside @graph). Malformed JSON sets schema_error.
     *
     * @param  array<string, mixed>  $result
     */
    private function collectSchemaTypes(string $json, array &$result): void
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            $result['schema_error'] = true;

            return;
        }

        $stack = [$decoded];

        while ($stack !== []) {
            $node = array_pop($stack);

            if (! is_array($node)) {
                continue;
            }

            if (isset($node['@type'])) {
                foreach ((array) $node['@type'] as $type) {
                    if (is_string($type) && $type !== '') {
                        $result['schema_types'][] = $type;
                    }
                }
            }

            foreach ($node['@graph'] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        $result['schema_types'] = array_values(array_unique($result['schema_types']));
    }

    /** RFC 3986 resolution against the page URL; null for non-http targets. */
    public function absolute(string $base, string $href): ?string
    {
        if ($href === '' || str_starts_with($href, '#')) {
            return null; // empty or same-page fragment
        }

        $scheme = strtolower((string) (parse_url($href, PHP_URL_SCHEME) ?? ''));

        if (in_array($scheme, ['mailto', 'tel', 'javascript', 'data', 'sms'], true)) {
            return null;
        }

        try {
            $resolved = UriResolver::resolve(new Uri($base), Utils::uriFor($href));
        } catch (\Throwable) {
            return null;
        }

        if (! in_array($resolved->getScheme(), ['http', 'https'], true)) {
            return null;
        }

        // fragments are noise for crawling and would fragment the frontier
        return (string) $resolved->withFragment('');
    }

    private function headerNoindex(?string $xRobotsTag): bool
    {
        return $xRobotsTag !== null && str_contains(strtolower($xRobotsTag), 'noindex');
    }

    private function countWords(string $text): int
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }

    private function cleanText(?string $text, int $max): string
    {
        $clean = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return mb_substr($clean, 0, $max);
    }

    /**
     * XPath predicate testing an attribute value case-insensitively — HTML
     * attribute values are case-insensitive but XPath string tests are not.
     */
    private function ciAttr(string $attribute, string $value): string
    {
        return sprintf(
            'translate(@%s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%s"',
            $attribute,
            strtolower($value)
        );
    }
}
