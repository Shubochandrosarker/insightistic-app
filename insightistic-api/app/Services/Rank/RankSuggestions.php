<?php

namespace App\Services\Rank;

use App\Models\RankKeyword;
use App\Models\SeoQueryDaily;
use App\Models\Site;
use Illuminate\Support\Facades\DB;

/**
 * Auto-suggested tracked keywords from GSC (Phase 10, doc 13). The rank
 * tracker should not make users type in what Search Console already knows:
 * the site's highest-impression queries of the last 28 days that are not
 * tracked yet become one-click suggestions.
 *
 * CONFIRMATION REQUIRED (doc 13: "Require user confirmation before
 * consuming rank-tracking quota"): suggest() never writes anything. Only
 * confirm() creates keywords, and it caps creation at the plan's
 * tracked_keywords entitlement — a confirm call that names more queries
 * than remain in the quota fills the quota and silently leaves the rest
 * untracked rather than 422-ing mid-list.
 */
class RankSuggestions
{
    /** The suggestion window: a 28-day GSC lookback ending today. */
    public const WINDOW_DAYS = 28;

    /**
     * Top untracked queries for the site, by summed impressions over the
     * window. Each row is {query, impressions, clicks, tracked: false};
     * already-tracked queries are filtered case-insensitively (and a
     * duplicate query under two casings in GSC itself is collapsed).
     *
     * @return array<int, array{query: string, impressions: int, clicks: int, tracked: bool}>
     */
    public function suggest(Site $site, int $limit = 20): array
    {
        $limit = max(1, $limit);

        // Lowercased+trimmed existing keywords, any active flag: a paused
        // keyword is still a keyword the user has already decided about.
        $tracked = RankKeyword::where('site_id', $site->id)
            ->pluck('keyword')
            ->map(fn ($keyword) => mb_strtolower(trim((string) $keyword)))
            ->all();

        // The aggregate fetch is padded past $limit because tracked/cased
        // rows are discarded client-side; the pad covers the tracked set
        // (bounded by the plan quota) plus a little headroom.
        $fetch = $limit + count($tracked) + 25;

        $rows = SeoQueryDaily::query()
            ->where('site_id', $site->id)
            ->where('date', '>=', now()->subDays(self::WINDOW_DAYS - 1)->toDateString())
            ->groupBy('query')
            ->orderByRaw('sum(impressions) desc')
            ->limit($fetch)
            ->get([
                'query',
                DB::raw('sum(impressions) as impressions'),
                DB::raw('sum(clicks) as clicks'),
            ]);

        $seen = [];

        $suggestions = [];

        foreach ($rows as $row) {
            $query = trim((string) $row->query);
            $key = mb_strtolower($query);

            if ($query === '' || in_array($key, $tracked, true) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            $suggestions[] = [
                'query'       => $query,
                'impressions' => (int) $row->impressions,
                'clicks'      => (int) $row->clicks,
                'tracked'     => false,
            ];

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * Turn confirmed suggestion queries into active tracked keywords with
     * the tracker defaults (google / desktop / no geo). Duplicates —
     * against the site's existing keywords case-insensitively, within the
     * batch, or by exact config hash — are skipped silently; creation
     * stops at the plan's remaining tracked_keywords budget.
     *
     * @param array<int, string> $queries
     * @return int the number of keywords actually created
     */
    public function confirm(Site $site, array $queries): int
    {
        $org = $site->organization;
        $limit = (int) ($org->plan?->limit('tracked_keywords') ?? 0);
        $used = RankKeyword::where('site_id', $site->id)->where('active', true)->count();
        $budget = max(0, $limit - $used);

        $existing = RankKeyword::where('site_id', $site->id)
            ->pluck('keyword')
            ->map(fn ($keyword) => mb_strtolower(trim((string) $keyword)))
            ->all();

        $created = 0;
        $seen = [];

        foreach ($queries as $query) {
            if ($created >= $budget) {
                break;
            }

            $keyword = trim((string) $query);
            $key = mb_strtolower($keyword);

            if ($key === ''
                || in_array($key, $existing, true)
                || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            // config_hash is pinned by direct assignment (not mass
            // assignment) so uniqueness never depends on the engine
            // model's $fillable drift.
            $row = new RankKeyword([
                'site_id'  => $site->id,
                'keyword'  => $keyword,
                'engine'   => 'google',
                'country'  => null,
                'language' => null,
                'device'   => 'desktop',
                'location' => null,
                'active'   => true,
            ]);
            $row->config_hash = RankKeyword::makeConfigHash($keyword, 'google', null, null, 'desktop', null);
            $row->save();

            $created++;
        }

        return $created;
    }
}
