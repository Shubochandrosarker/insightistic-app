<?php

namespace App\Console\Commands;

use App\Models\RankKeyword;
use App\Models\RankObservation;
use App\Services\Rank\DataForSeoProvider;
use App\Services\Rank\RankProvider;
use Illuminate\Console\Command;

/**
 * One-shot check after credentials are added: is the provider configured,
 * do the credentials work, what is the balance, and what will the current
 * keyword set cost per day (published price for the estimate, measured cost
 * from rank_observations once checks have run). Spends nothing: the
 * DataForSEO user_data endpoint is free.
 */
class RankProviderCheck extends Command
{
    protected $signature = 'insightistic:rank-provider-check';

    protected $description = 'Verify the rank tracking provider credentials and show measured vs estimated cost';

    public function handle(RankProvider $provider): int
    {
        $active = RankKeyword::query()->where('active', true)->count();

        $this->line('provider: ' . $provider->name());
        $this->line('configured: ' . ($provider->available() ? 'yes' : 'no'));
        $this->line('active keywords: ' . $active);

        $measured = RankObservation::query()
            ->where('checked_at', '>=', now()->subDays(30))
            ->selectRaw('count(*) as n, coalesce(sum(cost_units), 0) as cost')
            ->first();
        $n = (int) ($measured->n ?? 0);
        $cost = (float) ($measured->cost ?? 0);
        $this->line(sprintf('measured (30d): %d checks, $%.4f total, $%s per check', $n, $cost, $n > 0 ? number_format($cost / $n, 5) : 'n/a'));

        if ($provider instanceof DataForSeoProvider) {
            $this->line(sprintf('depth: %d (%d SERPs billed per check)', $provider->depth(), $provider->serpsPerCheck()));
            $this->line(sprintf(
                'estimate (published Live price $%.4f/SERP): $%.4f per check, $%.2f per day for %d keywords',
                DataForSeoProvider::LIVE_PRICE_PER_SERP_USD,
                $provider->estimatedCostPerCheckUsd(),
                $provider->estimatedCostPerCheckUsd() * $active,
                $active,
            ));

            if (! $provider->available()) {
                $this->warn('DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD not set: daily checks are skipped and cost nothing.');

                return self::SUCCESS;
            }

            $probe = $provider->userData();
            if (! $probe['ok']) {
                $this->error('credential check failed: ' . $probe['error']);

                return self::FAILURE;
            }

            $this->info('credentials: ok' . ($probe['balance_usd'] !== null ? sprintf(' (balance $%.2f)', $probe['balance_usd']) : ''));
        }

        return self::SUCCESS;
    }
}
