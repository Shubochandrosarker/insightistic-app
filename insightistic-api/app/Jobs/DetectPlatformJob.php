<?php

namespace App\Jobs;

use App\Models\Site;
use App\Support\PublicHttpGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

/**
 * Sniff which platform a freshly-added website runs on (2026-09-23
 * milestone). Platform is METADATA — it powers copy like "WordPress
 * detected — install the connector?" and nothing gates on it. Detection
 * is best-effort: timeouts, unreachable hosts and unknown platforms all
 * leave detected_platform null, which reads as "generic website".
 */
class DetectPlatformJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(public int $siteId)
    {
    }

    public function handle(): void
    {
        $site = Site::withoutGlobalScope('organization')->find($this->siteId);
        if (! $site || $site->detected_platform !== null) {
            return; // deleted meanwhile, or already detected
        }

        $url = $site->canonical_url ?: ('https://' . $site->normalized_domain);
        if (! $url) {
            return;
        }

        try {
            PublicHttpGuard::assertFetchable($url);
        } catch (\Throwable $e) {
            Log::info('platform-detect skipped', ['site_id' => $site->id, 'reason' => 'guard']);

            return;
        }

        try {
            $response = Http::timeout(15)
                ->connectTimeout(8)
                ->withUserAgent('InsightisticPlatformDetect/1.0 (+https://insightistic.com)')
                ->get($url);
        } catch (\Throwable) {
            return; // unreachable sites stay "generic" — not an error worth retrying
        }

        $site->detected_platform = $this->sniff(
            (string) $response->body(),
            $response->headers(),
        );
        $site->save();
    }

    /** @param array<string, mixed> $headers */
    private function sniff(string $html, array $headers): string
    {
        $headerText = collect($headers)->flatten()->implode(' ');
        $hay = strtolower($html . ' ' . $headerText);

        return match (true) {
            str_contains($hay, 'wp-content') || str_contains($hay, 'wp-json')
                || str_contains($hay, 'wp-includes')                    => str_contains($hay, 'woocommerce') ? 'woocommerce' : 'wordpress',
            str_contains($hay, 'cdn.shopify.com') || str_contains($hay, 'shopify.theme')
                || str_contains($hay, 'myshopify.com')                  => 'shopify',
            str_contains($hay, 'data-wf-page') || str_contains($hay, 'webflow')  => 'webflow',
            str_contains($hay, 'x-wix-request-id') || str_contains($hay, 'wix.com') => 'wix',
            str_contains($hay, 'squarespace')                           => 'squarespace',
            str_contains($hay, 'laravel_session') || str_contains($hay, 'xsrf-token') => 'laravel',
            str_contains($hay, 'x-nextjs') || str_contains($hay, '_next/static')     => 'headless',
            default                                                     => 'generic',
        };
    }
}
