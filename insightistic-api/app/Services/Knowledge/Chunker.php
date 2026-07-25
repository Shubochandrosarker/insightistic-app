<?php

namespace App\Services\Knowledge;

/**
 * Heading-aware text chunker. Targets ~800 tokens per chunk with ~15%
 * overlap, splitting on markdown headings first, then paragraphs, then
 * sentences. Token counts are estimated at ~4 characters/token — good
 * enough for sizing; exact counts come back from the embedding provider.
 */
class Chunker
{
    public function __construct(
        private int $targetTokens = 800,
        private float $overlapRatio = 0.15,
    ) {}

    /**
     * @return list<array{content: string, token_count: int, metadata: array}>
     */
    public function chunk(string $text, array $baseMetadata = []): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        $targetChars = $this->targetTokens * 4;

        // Split into heading-delimited sections (markdown # levels) so a
        // chunk rarely straddles two topics; each section remembers its
        // heading for citation display.
        $sections = $this->splitByHeadings($text);

        $chunks = [];
        foreach ($sections as $section) {
            foreach ($this->packSection($section['body'], $targetChars) as $piece) {
                $chunks[] = [
                    'content'     => $piece,
                    'token_count' => (int) ceil(mb_strlen($piece) / 4),
                    'metadata'    => array_filter($baseMetadata + ['heading' => $section['heading']]),
                ];
            }
        }

        return $chunks;
    }

    /** @return list<array{heading: ?string, body: string}> */
    private function splitByHeadings(string $text): array
    {
        $lines = explode("\n", $text);
        $sections = [];
        $heading = null;
        $buffer = [];

        $flush = function () use (&$sections, &$heading, &$buffer) {
            $body = trim(implode("\n", $buffer));
            if ($body !== '') {
                $sections[] = ['heading' => $heading, 'body' => $body];
            }
            $buffer = [];
        };

        foreach ($lines as $line) {
            if (preg_match('/^#{1,4}\s+(.+)$/', trim($line), $m)) {
                $flush();
                $heading = mb_substr(trim($m[1]), 0, 200);
                continue;
            }
            $buffer[] = $line;
        }
        $flush();

        return $sections !== [] ? $sections : [['heading' => null, 'body' => $text]];
    }

    /** @return list<string> */
    private function packSection(string $body, int $targetChars): array
    {
        if (mb_strlen($body) <= $targetChars) {
            return [$body];
        }

        // Paragraph packing with sentence-level fallback for giant paragraphs.
        $paragraphs = preg_split('/\n\s*\n/', $body) ?: [$body];
        $units = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (mb_strlen($p) > $targetChars) {
                foreach (preg_split('/(?<=[.!?])\s+/u', $p) ?: [$p] as $sentence) {
                    // Hard-wrap pathological unbroken runs.
                    foreach (mb_str_split($sentence, $targetChars) as $piece) {
                        $units[] = $piece;
                    }
                }
            } else {
                $units[] = $p;
            }
        }

        $chunks = [];
        $current = '';
        foreach ($units as $unit) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($unit) + 2 > $targetChars) {
                $chunks[] = trim($current);
                // Overlap: carry the tail of the previous chunk forward so
                // answers that straddle a boundary stay retrievable.
                $overlap = (int) ($targetChars * $this->overlapRatio);
                $current = $overlap > 0 ? mb_substr($current, -$overlap) . "\n" : '';
            }
            $current .= ($current === '' ? '' : "\n\n") . $unit;
        }
        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }
}
