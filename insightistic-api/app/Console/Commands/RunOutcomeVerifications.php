<?php

namespace App\Console\Commands;

use App\Models\OutcomeVerification;
use App\Models\Site;
use App\Services\Outcome\OutcomeVerificationService;
use Illuminate\Console\Command;

/** Verifies every due (unverified, metric-bearing, >=24h old) OutcomeVerification row (spec: Outcome Verification, P11). */
class RunOutcomeVerifications extends Command
{
    protected $signature = 'insightistic:outcome-verifications-run {--site= : Limit to one site id}';

    protected $description = 'Re-measure and classify every due outcome verification.';

    public function handle(OutcomeVerificationService $service): int
    {
        $due = OutcomeVerification::query()
            ->whereNull('verified_at')
            ->whereNotNull('watched_metric_id')
            ->where('created_at', '<=', now()->subHours(24))
            ->when($this->option('site'), fn ($q, $siteId) => $q->where('site_id', $siteId))
            ->get();

        if ($due->isEmpty()) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        $sitesById = Site::query()->whereIn('id', $due->pluck('site_id')->unique())->get()->keyBy('id');

        $verified = 0;
        foreach ($due as $row) {
            $site = $sitesById->get($row->site_id);
            if (! $site || ! $service->isDue($row)) {
                continue;
            }
            $service->verify($row, $site);
            $verified++;
        }

        $this->info("Verified {$verified} outcome(s).");

        return self::SUCCESS;
    }
}
