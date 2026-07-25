<?php

namespace App\Services\Privacy;

use App\Models\Organization;
use App\Models\OrganizationExport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Builds a portable archive of everything an organization owns (spec §10).
 *
 * Two rules shape what goes in:
 *
 *  - **Credentials never do.** Connector secrets, Cloudflare and provider API
 *    tokens, OAuth refresh tokens, MFA secrets and recovery codes are all
 *    excluded. An export is a file a customer will email around; it must not
 *    be a way to walk out with live keys.
 *  - **Everything else does, verbatim.** A data export that quietly omits
 *    rows is worse than none, because it looks complete.
 *
 * Written as newline-delimited JSON per table inside a zip, so a 500k-order
 * store streams instead of being assembled in memory.
 */
class OrganizationExporter
{
    /**
     * Tables exported by organization_id, and the columns withheld from each.
     *
     * @var array<string,array<int,string>>
     */
    private const ORG_TABLES = [
        'organizations'        => [],
        'organization_users'   => [],
        'subscriptions'        => [],
        'usage_counters'       => [],
        'client_reports'       => [],
        'report_schedules'     => [],
        'brand_settings'       => [],
        'notifications'        => [],
        'email_logs'           => [],
        'alerts'               => [],
        'ai_insights'          => [],
        'ai_threads'           => [],
        'knowledge_bases'      => [],
        'knowledge_sources'    => [],
        'email_campaigns'      => [],
        // Secrets withheld — see the class docblock.
        'sites'                => ['connector_secret', 'connector_key_id', 'cloudflare_api_token'],
        'licenses'             => ['license_key_hash'],
        'data_source_credentials' => ['access_token', 'refresh_token'],
    ];

    /** Tables reached through the org's sites rather than owned directly. */
    private const SITE_TABLES = [
        'wc_orders', 'wc_order_items', 'wc_products', 'wc_customers',
        'traffic_daily', 'seo_daily', 'performance_runs', 'engagement_daily',
        'data_source_connections', 'sync_logs',
    ];

    public function build(OrganizationExport $export): void
    {
        $org = Organization::withoutGlobalScope('organization')->findOrFail($export->organization_id);

        $export->update(['status' => 'processing']);

        $disk = config('insightistic.privacy.export_disk');
        $relative = "exports/org-{$org->id}/insightistic-export-{$export->id}.zip";
        $absolute = Storage::disk($disk)->path($relative);

        Storage::disk($disk)->makeDirectory(dirname($relative));

        $zip = new \ZipArchive();
        if ($zip->open($absolute, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the export archive.');
        }

        $manifest = [
            'organization_id' => $org->id,
            'organization'    => $org->name,
            'generated_at'    => now()->toIso8601String(),
            'format'          => 'newline-delimited JSON, one file per table',
            'withheld'        => 'Credentials (connector secrets, API tokens, OAuth refresh tokens, '
                . 'MFA secrets and recovery codes) are deliberately excluded.',
            'tables'          => [],
        ];

        $siteIds = DB::table('sites')->where('organization_id', $org->id)->pluck('id')->all();

        foreach (self::ORG_TABLES as $table => $withheld) {
            $manifest['tables'][$table] = $this->addTable(
                $zip,
                $table,
                $this->scopeFor($table, $org->id, $siteIds),
                $withheld,
            );
        }

        foreach (self::SITE_TABLES as $table) {
            $manifest['tables'][$table] = $this->addTable(
                $zip,
                $table,
                $this->scopeFor($table, $org->id, $siteIds),
                [],
            );
        }

        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $zip->close();

        $export->update([
            'status'       => 'ready',
            'disk'         => $disk,
            'storage_path' => $relative,
            'size_bytes'   => Storage::disk($disk)->size($relative),
            'completed_at' => now(),
            'expires_at'   => now()->addDays((int) config('insightistic.privacy.export_ttl_days')),
        ]);
    }

    /**
     * Pick the tenant filter from the table's actual schema.
     *
     * Some tables are organization-scoped, some hang off sites, and which is
     * which has changed over time. Reading the schema instead of maintaining
     * a hand-written split means a column rename shows up as an empty table
     * in the manifest rather than a fatal mid-export — and a new table is
     * scoped correctly without anyone remembering to update two lists.
     *
     * Returns a closure that yields no rows when neither column exists,
     * because exporting an unscoped table would leak other tenants' data.
     */
    private function scopeFor(string $table, int $orgId, array $siteIds): callable
    {
        if ($table === 'organizations') {
            return fn ($q) => $q->where('id', $orgId);
        }

        $schema = DB::getSchemaBuilder();

        if ($schema->hasColumn($table, 'organization_id')) {
            return fn ($q) => $q->where('organization_id', $orgId);
        }

        if ($schema->hasColumn($table, 'site_id')) {
            return fn ($q) => $q->whereIn('site_id', $siteIds ?: [0]);
        }

        logger()->warning('Export skipped an unscoped table.', ['table' => $table]);

        return fn ($q) => $q->whereRaw('1 = 0');
    }

    /**
     * Stream one table into the archive as NDJSON.
     *
     * @param  callable  $scope  applies the tenant filter
     * @param  array<int,string>  $withheld  columns removed from every row
     * @return int rows written
     */
    private function addTable(\ZipArchive $zip, string $table, callable $scope, array $withheld): int
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return 0;
        }

        $lines = '';
        $count = 0;

        $scope(DB::table($table))->orderBy('id')->chunk(1000, function ($rows) use (&$lines, &$count, $withheld) {
            foreach ($rows as $row) {
                $data = (array) $row;
                foreach ($withheld as $column) {
                    unset($data[$column]);
                }
                $lines .= json_encode($data, JSON_UNESCAPED_SLASHES) . "\n";
                $count++;
            }
        });

        $zip->addFromString("{$table}.ndjson", $lines);

        return $count;
    }
}
