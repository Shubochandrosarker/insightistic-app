<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\OrganizationExport;
use App\Services\Privacy\OrganizationPurger;
use App\Services\Privacy\RetentionPurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Nightly privacy maintenance (spec Phase 10):
 *
 *  1. Purge analytics older than each plan's data_retention_days.
 *  2. Expire and delete export archives past their TTL.
 *  3. Carry out organization deletions whose grace window has elapsed.
 *
 * Deletion runs last: if a purge step fails, the tenant's data is still
 * there to try again with, rather than half-deleted.
 */
class RunPrivacyMaintenance extends Command
{
    protected $signature = 'insightistic:privacy-maintenance
                            {--dry-run : Report what would happen without changing anything}';

    protected $description = 'Enforce data retention, expire exports, and complete scheduled organization deletions';

    public function handle(RetentionPurger $retention, OrganizationPurger $purger): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->purgeRetention($retention, $dry);
        $this->expireExports($dry);
        $this->completeDeletions($purger, $dry);

        return self::SUCCESS;
    }

    private function purgeRetention(RetentionPurger $retention, bool $dry): void
    {
        $total = 0;

        Organization::with('plan')->whereNull('deletion_scheduled_for')->chunkById(100, function ($orgs) use ($retention, $dry, &$total) {
            foreach ($orgs as $org) {
                $days = $retention->retentionDays($org);

                if ($days === null || $days <= 0) {
                    continue;
                }

                if ($dry) {
                    $this->line("would purge org {$org->id} beyond {$days} days");

                    continue;
                }

                foreach ($retention->purgeOrganization($org) as $table => $count) {
                    $total += $count;
                    $this->line("org {$org->id}: removed {$count} from {$table}");
                }
            }
        });

        $this->info($dry ? 'Retention: dry run complete.' : "Retention: {$total} row(s) purged.");
    }

    private function expireExports(bool $dry): void
    {
        $expired = 0;

        OrganizationExport::withoutGlobalScope('organization')
            ->where('status', 'ready')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(100, function ($exports) use ($dry, &$expired) {
                foreach ($exports as $export) {
                    if ($dry) {
                        $this->line("would expire export {$export->id}");

                        continue;
                    }

                    // Remove the archive itself, not just the row — the file
                    // is the sensitive part.
                    if ($export->storage_path && $export->disk && Storage::disk($export->disk)->exists($export->storage_path)) {
                        Storage::disk($export->disk)->delete($export->storage_path);
                    }

                    $export->update(['status' => 'expired', 'storage_path' => null, 'size_bytes' => null]);
                    $expired++;
                }
            });

        $this->info($dry ? 'Exports: dry run complete.' : "Exports: {$expired} expired.");
    }

    private function completeDeletions(OrganizationPurger $purger, bool $dry): void
    {
        $done = 0;

        $due = Organization::whereNotNull('deletion_scheduled_for')
            ->where('deletion_scheduled_for', '<=', now())
            ->get();

        foreach ($due as $org) {
            if ($dry) {
                $this->line("would delete org {$org->id} ({$org->name})");

                continue;
            }

            $result = $purger->purge($org);
            $done++;

            $this->warn(
                "Deleted org {$org->id}: {$result['sites']} site(s), "
                . "{$result['files']} file(s), {$result['users']} orphaned user(s)."
            );
        }

        $this->info($dry ? 'Deletions: dry run complete.' : "Deletions: {$done} organization(s) removed.");
    }
}
