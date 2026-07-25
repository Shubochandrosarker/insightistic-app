<?php

namespace App\Services\Knowledge;

use League\HTMLToMarkdown\HtmlConverter;

/**
 * Turns a stored source (file bytes / HTML / manual text) into clean plain
 * text ready for chunking. One extractor per supported format; unknown
 * formats throw so the source is marked failed with a clear error_code.
 */
class TextExtractor
{
    public const SUPPORTED_MIMES = [
        'application/pdf'                                                           => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'docx',
        'text/plain'                                                                => 'text',
        'text/markdown'                                                             => 'text',
        'text/csv'                                                                  => 'csv',
        'application/csv'                                                           => 'csv',
        'application/json'                                                          => 'json',
        'text/html'                                                                 => 'html',
        'application/xhtml+xml'                                                     => 'html',
    ];

    public const EXTENSION_MIMES = [
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'txt'  => 'text/plain',
        'md'   => 'text/markdown',
        'markdown' => 'text/markdown',
        'csv'  => 'text/csv',
        'json' => 'application/json',
        'html' => 'text/html',
        'htm'  => 'text/html',
    ];

    public function extract(string $bytes, string $mimeType): string
    {
        $kind = self::SUPPORTED_MIMES[strtolower(explode(';', $mimeType)[0])] ?? null;

        $text = match ($kind) {
            'pdf'  => $this->fromPdf($bytes),
            'docx' => $this->fromDocx($bytes),
            'csv'  => $this->fromCsv($bytes),
            'json' => $this->fromJson($bytes),
            'html' => $this->fromHtml($bytes),
            'text' => $bytes,
            default => throw new \RuntimeException("Unsupported content type: {$mimeType}"),
        };

        return $this->normalize($text);
    }

    private function fromPdf(string $bytes): string
    {
        $parser = new \Smalot\PdfParser\Parser();

        return $parser->parseContent($bytes)->getText();
    }

    private function fromDocx(string $bytes): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ins-docx-');
        try {
            file_put_contents($tmp, $bytes);
            $phpWord = \PhpOffice\PhpWord\IOFactory::load($tmp, 'Word2007');

            $out = [];
            foreach ($phpWord->getSections() as $section) {
                $this->collectWordText($section->getElements(), $out);
            }

            return implode("\n", $out);
        } finally {
            @unlink($tmp);
        }
    }

    private function collectWordText(array $elements, array &$out): void
    {
        foreach ($elements as $element) {
            if (method_exists($element, 'getText')) {
                $text = $element->getText();
                if (is_string($text) && $text !== '') {
                    $out[] = $text;
                }
            }
            if (method_exists($element, 'getElements')) {
                $this->collectWordText($element->getElements(), $out);
            }
        }
    }

    private function fromCsv(string $bytes): string
    {
        // Render rows as "header: value" lines so retrieval matches cell values
        // with their column meaning instead of raw comma soup.
        $lines = preg_split('/\r\n|\r|\n/', trim($bytes)) ?: [];
        if ($lines === []) {
            return '';
        }

        $header = str_getcsv(array_shift($lines));
        $out = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line);
            $pairs = [];
            foreach ($row as $i => $value) {
                $key = trim((string) ($header[$i] ?? "col{$i}"));
                if (trim((string) $value) !== '') {
                    $pairs[] = "{$key}: {$value}";
                }
            }
            if ($pairs !== []) {
                $out[] = implode(' | ', $pairs);
            }
        }

        return implode("\n", $out);
    }

    private function fromJson(string $bytes): string
    {
        $decoded = json_decode($bytes, true);
        if ($decoded === null && trim($bytes) !== 'null') {
            throw new \RuntimeException('Invalid JSON document.');
        }

        $out = [];
        $this->flattenJson($decoded, '', $out);

        return implode("\n", $out);
    }

    private function flattenJson(mixed $value, string $path, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $this->flattenJson($v, $path === '' ? (string) $k : "{$path}.{$k}", $out);
            }

            return;
        }

        $scalar = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        if (trim($scalar) !== '') {
            $out[] = "{$path}: {$scalar}";
        }
    }

    /**
     * HTML → text with boilerplate removal: scripts, styles, nav, header,
     * footer and asides are dropped before conversion; markdown keeps the
     * heading structure the chunker splits on.
     */
    private function fromHtml(string $bytes): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8"?>' . $bytes, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        if (! $loaded) {
            return strip_tags($bytes);
        }

        foreach (['script', 'style', 'noscript', 'nav', 'header', 'footer', 'aside', 'form', 'iframe', 'svg', 'template'] as $tag) {
            $nodes = iterator_to_array($dom->getElementsByTagName($tag));
            foreach ($nodes as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // Prefer <main>/<article> when present — the strongest boilerplate cue.
        $root = null;
        foreach (['main', 'article'] as $tag) {
            $candidates = $dom->getElementsByTagName($tag);
            if ($candidates->length > 0) {
                $root = $candidates->item(0);
                break;
            }
        }

        $html = $dom->saveHTML($root ?: $dom->documentElement ?: null) ?: '';

        $converter = new HtmlConverter([
            'strip_tags'   => true,
            'remove_nodes' => 'img picture video audio button',
        ]);

        try {
            return $converter->convert($html);
        } catch (\Throwable) {
            return trim(strip_tags($html));
        }
    }

    /** Whether an HTML document opts out of indexing (robots meta noindex). */
    public function htmlHasNoindex(string $bytes): bool
    {
        return (bool) preg_match(
            '/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i',
            $bytes
        );
    }

    private function normalize(string $text): string
    {
        // Collapse aggressive whitespace but keep paragraph breaks (chunker cue).
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
