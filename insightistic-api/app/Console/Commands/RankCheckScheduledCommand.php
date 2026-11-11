<?php

namespace App\Console\Commands;

use App\Domain\Billing\EntitlementResolver;
use App\Jobs\RankCheckJob;
use App\Models\Organization;
use App\Models\RankKeyword;
use App\Models\Site;
use App\Services\Rank\RankProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Scheduled rank checks (Phase 10, doc 13). The nightly 06:00 schedule
 * entry runs this command; the per-site "who has tracked keywords?"
 * decision happens here so the schedule entry itself stays dumb.
 *
 * Every active site with at least one active rank keyword gets one queued
 * RankCheckJob — the provider batch, same-day suppression and metering all
 * happen inside the job/orchestrator, so an overlapping or retried command
 * run never duplicates observations or double-bills. Same-day suppression
 * also means a re-run later in the day is a safe no-op.
 *
 * Cadence: the command runs nightly, but a plan's rank_cadence decides who
 * is actually due. Daily plans (starter/growth/agency/agency_pro) dispatch
 * every night; weekly plans (free and every lifetime tier — the LTD price
 * only works at one check per keyword per week) dispatch on one stable
 * weekday per organization, derived from crc32(org id) so the same org
 * always lands on the same day and the load spreads evenly across the week.
 * A plan that is not catalogued in config/commercial.php keeps the old
 * every-night behaviour. --force ignores cadence for manual runs/tests.
 *
 * When the provider is not configured the command logs a warning and exits
 * SUCCESS: unconfigured credentials are an expected state (free plans,
 * fresh installs), not a failure — rank tracking simply costs nothing.
 */
class RankCheckScheduledCommand extends Command
{
    /** Cadence used for plans that do not declare one (legacy plan rows). */
    private const DEFAULT_CADENCE = 'daily';

    protected $signature = 'insightistic:rank-check
                            {--site= : Limit to one site id}
                            {--force : Ignore the per-plan rank cadence (manual runs/tests)}';

    protected $description = 'Queue due SERP rank checks for sites with active tracked keywords';

    public function handle(): int
    {
        $provider = app(RankProvider::class);

        if (! $provider->available()) {
            $this->warn(sprintf('Rank tracking provider "%s" is not configured (credentials missing) — skipping rank checks.', $provider->name()));

            return self::SUCCESS;
        }

        $dispatched = 0;
        $cadenceSkipped = 0;
        $skipped = 0;

        $entitlements = app(EntitlementResolver::class);
        $cadences = $this->planCadences();
        $cadenceByOrg = [];
        $force = (bool) $this->option('force');
        $today = now();

        // Sites that actually track at least one active keyword (Site has no
        // rankKeywords relation — resolve through the keyword table).
        $siteIds = RankKeyword::query()
            ->where('active', true)
            ->distinct()
            ->pluck('site_id');

        Site::withoutGlobalScope('organization')
            ->when($this->option('site'), fn ($query) => $query->whereKey($this->option('site')))
            ->whereIn('id', $siteIds)
            ->whereNull('archived_at')
            ->with('organization')
            ->chunkById(100, function ($sites) use (&$dispatched, &$cadenceSkipped, &$skipped, &$cadenceByOrg, $entitlements, $cadences, $force, $today) {
                foreach ($sites as $site) {
                    $org = $site->organization;

                    if (! $org || $org->deletion_scheduled_for !== null) {
                        $skipped++;
                        continue;
                    }

                    if (! $force && ! $this->dueForCadence($org, $entitlements, $cadences, $cadenceByOrg, $today)) {
                        $cadenceSkipped++;
                        continue;
                    }

                    RankCheckJob::dispatch($site->id);
                    $dispatched++;
                }
            });

        $this->info("Rank checks: {$dispatched} dispatched, {$cadenceSkipped} cadence-skipped, {$skipped} skipped.");

        return self::SUCCESS;
    }

    /**
     * slug => cadence for every catalogued tier, so one lookup serves the
     * regular, Founding Angel and AppSumo arrays.
     *
     * @return array<string, string>
     */
    private function planCadences(): array
    {
        $plans = array_merge(
            config('commercial.regular_plans', []),
            config('commercial.angel_plans', []),
            config('commercial.appsumo_plans', []),
        );

        $cadences = [];

        foreach ($plans as $slug => $plan) {
            $cadences[$slug] = ($plan['rank_cadence'] ?? self::DEFAULT_CADENCE) === 'weekly'
                ? 'weekly'
                : self::DEFAULT_CADENCE;
        }

        return $cadences;
    }

    /** Whether the site's organization is due today under its plan cadence. */
    private function dueForCadence(
        Organization $organization,
        EntitlementResolver $entitlements,
        array $cadences,
        array &$cache,
        Carbon $today,
    ): bool {
        if ($this->cadenceFor($organization, $entitlements, $cadences, $cache) !== 'weekly') {
            return true;
        }

        return $this->weeklySlotMatches($organization, $today);
    }

    /**
     * The org's effective plan cadence, memoised per org id (one org owns
     * many sites). EntitlementResolver is the same authority every other
     * plan-aware caller uses, so a lifetime grant or admin override is
     * reflected here without a second billing path.
     */
    private function cadenceFor(
        Organization $organization,
        EntitlementResolver $entitlements,
        array $cadences,
        array &$cache,
    ): string {
        if (! array_key_exists($organization->id, $cache)) {
            $slug = $entitlements->resolve($organization)['plan']['slug'] ?? null;

            $cache[$organization->id] = $slug === null
                ? self::DEFAULT_CADENCE
                : ($cadences[$slug] ?? self::DEFAULT_CADENCE);
        }

        return $cache[$organization->id];
    }

    /**
     * One stable weekday per organization: crc32(org id) % 7, Monday = 0.
     * Deterministic across runs and hosts, and spreads weekly orgs evenly
     * over the week instead of checking every LTD site on the same night.
     */
    private function weeklySlotMatches(Organization $organization, Carbon $today): bool
    {
        return crc32((string) $organization->id) % 7 === ((int) $today->format('N')) - 1;
    }
}
