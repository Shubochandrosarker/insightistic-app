<?php

namespace App\Console\Commands;

use App\Models\License;
use App\Models\Organization;
use App\Services\LicenseService;
use App\Services\LifecycleMailer;
use Illuminate\Console\Command;

/**
 * One-time backfill: issue a license to every organization created before the
 * license layer shipped. The raw key is emailed to the owner (the only place
 * it appears) and can be regenerated from the dashboard afterwards.
 */
class BackfillLicenses extends Command
{
    protected $signature = 'insightistic:licenses-backfill {--dry-run : List orgs without issuing}';

    protected $description = 'Issue licenses for organizations that predate the license system.';

    public function handle(LicenseService $licenses, LifecycleMailer $mailer): int
    {
        $orgs = Organization::with(['plan', 'owner'])
            ->whereNotIn('id', License::withoutGlobalScope('organization')->select('organization_id'))
            ->get();

        if ($orgs->isEmpty()) {
            $this->info('Every organization already has a license.');
            return self::SUCCESS;
        }

        foreach ($orgs as $org) {
            if ($this->option('dry-run')) {
                $this->line("Would issue license: org #{$org->id} {$org->name}");
                continue;
            }

            $raw = $licenses->issue($org);
            $mailer->send($org, 'trial_started', ['license_key' => $raw], 'backfill');
            $this->info("Issued license for org #{$org->id} {$org->name} (…" . substr($raw, -4) . ')');
        }

        return self::SUCCESS;
    }
}
