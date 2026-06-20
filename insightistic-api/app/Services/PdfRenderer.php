<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Wraps dompdf (pure PHP — no Node/puppeteer). Requires:
 *   composer require barryvdh/laravel-dompdf
 *
 * isRemoteEnabled lets the report pull a white-label logo from its storage URL.
 */
class PdfRenderer
{
    public function fromHtml(string $html): string
    {
        return Pdf::loadHTML($html)
            ->setPaper('a4')
            ->setOption('isRemoteEnabled', true)
            ->output();
    }
}
