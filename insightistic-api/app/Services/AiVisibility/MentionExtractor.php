<?php

namespace App\Services\AiVisibility;

use App\Models\Site;

/**
 * Pure extraction over one AI answer text (Phase 11, doc 14): which entities
 * were mentioned (owned brand / competitors, with order of appearance and a
 * heuristic sentiment) and which http(s) URLs were cited (with owned-domain
 * resolution). No DB access — PromptRunner owns persistence, tests can call
 * this directly.
 *
 * HEURISTIC DISCLOSURE (doc 14 asks for "sentiment/stance if confidently
 * derived"): the sentiment classifier is deliberately naive — it looks at a
 * ±window of words around the mention and maps (avoid|worst|beware) →
 * negative, (great|best|leading|recommended) → positive, anything else →
 * neutral. It is a documented approximation, never provider ground truth,
 * and the UI must present it as such.
 *
 * ORDER DISCLAIMER (doc 14): position_order is the order of FIRST appearance
 * in the answer text. Doc 14 explicitly warns not to call an ordinal mention
 * order a universal "rank" — providers don't guarantee the answer's
 * ordering is meaningful, so the UI labels this "order of appearance".
 */
class MentionExtractor
{
    /** Positive near-words for the naive sentiment heuristic (doc 14 examples). */
    public const POSITIVE_WORDS = ['great', 'best', 'leading', 'recommended'];

    /** Negative near-words for the naive sentiment heuristic (doc 14 examples). */
    public const NEGATIVE_WORDS = ['avoid', 'worst', 'beware'];

    /** Characters looked at on each side of a mention for sentiment. */
    private const SENTIMENT_WINDOW = 80;

    /**
     * Extract mentions and citations from one answer.
     *
     * @param  iterable<\App\Models\AiVisibilityCompetitor>  $competitors  the site's competitor definitions (name + aliases)
     * @return array{
     *     mentions: list<array{entity_name: string, is_owned: bool, is_competitor: bool, position_order: int, sentiment: string}>,
     *     citations: list<array{url: string, domain: string, is_owned: bool}>,
     *     brand_mentioned: bool,
     * }
     */
    public function extract(string $text, Site $site, iterable $competitors = []): array
    {
        // One row per entity, keyed by the lowercased match string so the
        // same string appearing via two definitions cannot double-count.
        $candidates = [];

        foreach ($this->ownedNames($site) as $name) {
            $this->consider($candidates, $text, $name, isOwned: true, displayName: $name);
        }

        foreach ($competitors as $competitor) {
            $aliases = array_merge(
                [$competitor->name],
                is_array($competitor->aliases_json) ? $competitor->aliases_json : [],
            );

            foreach ($aliases as $alias) {
                $this->consider($candidates, $text, (string) $alias, isOwned: false, displayName: $competitor->name);
            }
        }

        usort($candidates, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        $mentions = [];
        foreach (array_values($candidates) as $index => $candidate) {
            $mentions[] = [
                'entity_name'     => $candidate['display'],
                'is_owned'        => $candidate['is_owned'],
                'is_competitor'   => ! $candidate['is_owned'],
                'position_order'  => $index + 1,
                'sentiment'       => $this->sentimentNear($text, $candidate['offset'], mb_strlen($candidate['match'])),
            ];
        }

        return [
            'mentions'        => $mentions,
            'citations'       => $this->extractCitations($text, $site),
            'brand_mentioned' => $mentions !== [] && collect($mentions)->contains(fn ($m) => $m['is_owned']),
        ];
    }

    /**
     * All http(s) URLs in the answer, deduplicated, with domain and
     * owned-domain resolution (site domain match, www-insensitive).
     *
     * @return list<array{url: string, domain: string, is_owned: bool}>
     */
    public function extractCitations(string $text, Site $site): array
    {
        preg_match_all('/https?:\/\/[^\s<>"\')\]]+/i', $text, $matches);

        $siteHost = $this->normalizeHost((string) $site->domain);
        $seen = [];
        $citations = [];

        foreach ($matches[0] as $url) {
            // Sentence punctuation glues onto URLs inside prose — strip it.
            $url = rtrim($url, ".,;:!?");

            if ($url === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $host = $this->normalizeHost((string) (parse_url($url, PHP_URL_HOST) ?: ''));
            if ($host === null) {
                continue;
            }

            $citations[] = [
                'url'      => $url,
                'domain'   => $host,
                'is_owned' => $siteHost !== null && $host === $siteHost,
            ];
        }

        return $citations;
    }

    /** The owned-brand match strings: site name + organization name, deduped. */
    private function ownedNames(Site $site): array
    {
        return array_values(array_unique(array_filter([
            trim((string) $site->name),
            trim((string) $site->organization?->name),
        ], fn ($name) => $name !== '')));
    }

    /**
     * Find the entity's first whole-word-ish occurrence and register it as a
     * candidate (first match wins across all names of the same entity).
     * "Whole-word-ish": the characters immediately around the match must not
     * be alphanumeric, so "Pro" doesn't match inside "Profile" — the same
     * guard that keeps the alias list safe to include short abbreviations.
     *
     * @param array<string, array{offset: int, match: string, display: string, is_owned: bool}> $candidates
     */
    private function consider(array &$candidates, string $text, string $name, bool $isOwned, string $displayName): void
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) < 2) {
            return; // 1-char names would flood every answer with matches.
        }

        $offset = $this->firstOffset($text, $name);

        if ($offset !== null && ! isset($candidates[mb_strtolower($name)])) {
            $candidates[mb_strtolower($name)] = [
                'offset'   => $offset,
                'match'    => $name,
                'display'  => $displayName,
                'is_owned' => $isOwned,
            ];
        }
    }

    /** Case-insensitive first occurrence with alphanumeric boundary check. */
    private function firstOffset(string $haystack, string $needle): ?int
    {
        $hay = mb_strtolower($haystack);
        $nee = mb_strtolower($needle);
        $len = mb_strlen($hay);
        $neeLen = mb_strlen($nee);
        $from = 0;

        while (($pos = mb_strpos($hay, $nee, $from)) !== false) {
            $before = $pos > 0 ? mb_substr($hay, $pos - 1, 1) : ' ';
            $afterAt = $pos + $neeLen;
            $after = $afterAt < $len ? mb_substr($hay, $afterAt, 1) : ' ';

            if (! preg_match('/[a-z0-9]/u', $before) && ! preg_match('/[a-z0-9]/u', $after)) {
                return $pos;
            }

            $from = $pos + 1;
        }

        return null;
    }

    /**
     * Naive sentiment: scan the ±SENTIMENT_WINDOW window around the first
     * occurrence for the documented word lists (negative wins over positive
     * so "avoid the worst" style warnings are not read as praise).
     */
    private function sentimentNear(string $text, int $offset, int $matchLen): string
    {
        $start = max(0, $offset - self::SENTIMENT_WINDOW);
        $window = mb_strtolower(mb_substr(
            $text,
            $start,
            min(mb_strlen($text), $offset + $matchLen + self::SENTIMENT_WINDOW) - $start,
        ));

        foreach (self::NEGATIVE_WORDS as $word) {
            if (str_contains($window, $word)) {
                return \App\Models\AiVisibilityMention::SENTIMENT_NEGATIVE;
            }
        }

        foreach (self::POSITIVE_WORDS as $word) {
            if (str_contains($window, $word)) {
                return \App\Models\AiVisibilityMention::SENTIMENT_POSITIVE;
            }
        }

        return \App\Models\AiVisibilityMention::SENTIMENT_NEUTRAL;
    }

    /** Lowercased host with a leading www. stripped (null when empty). */
    private function normalizeHost(string $host): ?string
    {
        $host = mb_strtolower(trim($host));

        if ($host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
