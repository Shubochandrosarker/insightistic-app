<?php

namespace App\Services\Crawler;

use App\Models\CrawlRun;
use App\Models\Organization;
use App\Models\Site;
use App\Services\UsageService;

final class CrawlBudget
{
    private const MAX_PAGES_PER_RUN = 1000;

    public function __construct(private UsageService $usage) {}

    public function summary(Organization $org, ?Site $site = null): array
    {
        $period = now()->startOfMonth();
        $rows = CrawlRun::query()
            ->whereIn('site_id', $org->sites()->withoutGlobalScope('organization')->select('sites.id'))
            ->where('created_at', '>=', $period)
            ->selectRaw("site_id, SUM(pages_crawled) AS used,
                SUM(CASE WHEN status IN ('pending', 'running')
                    THEN GREATEST(max_pages - pages_crawled, 0) ELSE 0 END) AS reserved")
            ->groupBy('site_id')->get();

        $limit = $this->usage->limit($org, 'http_crawl_pages_monthly');
        $used = (int) $rows->sum('used');
        $reserved = (int) $rows->sum('reserved');
        $selected = $site ? $rows->firstWhere('site_id', $site->id) : null;
        $remaining = max(0, $limit - $used - $reserved);
        $projectUsed = (int) $selected?->used;
        $projectReserved = (int) $selected?->reserved;
        $projectLimit = $site?->crawl_pages_monthly_limit;
        $projectRemaining = $projectLimit === null
            ? $remaining
            : min($remaining, max(0, $projectLimit - $projectUsed - $projectReserved));

        return [
            'workspace' => ['limit' => $limit, 'used' => $used, 'reserved' => $reserved, 'remaining' => $remaining],
            'project' => [
                'used' => $projectUsed, 'reserved' => $projectReserved,
                'limit' => $projectLimit, 'remaining' => $projectRemaining,
            ],
            'max_next_run' => min(self::MAX_PAGES_PER_RUN, $projectRemaining),
            'resets_at' => $period->copy()->addMonth()->toDateString(),
        ];
    }
}
