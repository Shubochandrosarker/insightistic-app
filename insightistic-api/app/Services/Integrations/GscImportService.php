<?php

namespace App\Services\Integrations;

use App\Jobs\BackfillGscHistory;
use App\Jobs\SyncDataSource;
use App\Models\Organization;
use App\Models\ProviderAccount;
use App\Models\Site;
use App\Services\Sites\SiteKickoff;
use App\Support\DomainNormalizer;
use Illuminate\Support\Facades\Queue;

/**
 * Organization-level Search Console importer (spec §7) — the recommended
 * "Add Website" path: list every property the org's Google grant can read,
 * multi-select, create the missing sites, attach GSC, and start the full
 * initial pipeline (search sync + backfill + crawl + uptime + PageSpeed).
 *
 * Property shapes handled:
 *   sc-domain:example.com        → domain property
 *   https://example.com/         → URL-prefix property (host-only key)
 *   https://example.com/shop/    → URL-prefix property with path
 */
class GscImportService
{
    public function __construct(
        private readonly GscClient $gsc,
        private readonly ProviderAccountManager $accounts,
        private readonly SiteKickoff $kickoff,
    ) {
    }

    /**
     * Every readable GSC property with the matching Insightistic project
     * (null when none matches), for the multi-select importer UI.
     */
    public function availableProperties(Organization $org): array
    {
        $account = $this->accounts->googleAccount($org);

        if (! $account) {
            throw new IntegrationFailure('org_google_not_connected', 'Connect Google for your organization first.');
        }

        $sitesByDomain = Site::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get()
            ->mapWithKeys(fn (Site $s) => [$s->normalized_domain => $s]);

        return array_map(function (array $property) use ($sitesByDomain, $org) {
            $domain = self::propertyDomain($property['id']);

            return [
                'property'        => $property['id'],
                'property_type'   => str_starts_with($property['id'], 'sc-domain:') ? 'domain' : 'url_prefix',
                'permission_level' => $property['permission_level'] ?? $property['account'] ?? '',
                'domain'          => $domain,
                'matched_site'    => ($match = $sitesByDomain->get(DomainNormalizer::hostKey($domain)))
                    ? ['id' => $match->id, 'name' => $match->name]
                    : null,
            ];
        }, $this->gsc->listResourcesForAccount($account));
    }

    /**
     * Import selected properties: create missing sites (respecting the plan
     * limit), attach GSC, queue the initial sync + historical backfill +
     * crawl/uptime/PageSpeed kickoff.
     *
     * @param string[] $properties raw GSC siteUrl values ("sc-domain:…" or "https://…")
     * @return array{created:array<int,array<string,mixed>>,attached:array<int,array<string,mixed>>,skipped:array<int,array<string,mixed>>}
     */
    public function import(Organization $org, array $properties, int $userId): array
    {
        $account = $this->accounts->googleAccount($org);

        if (! $account) {
            throw new IntegrationFailure('org_google_not_connected', 'Connect Google for your organization first.');
        }

        // Only import properties the grant can actually read.
        $readable = collect($this->gsc->listResourcesForAccount($account))->keyBy('id');
        $existing = Site::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->get()
            ->keyBy(fn (Site $s) => $s->normalized_domain);

        $limit = $org->plan?->site_limit ?? 1;
        $used  = Site::withoutGlobalScope('organization')->where('organization_id', $org->id)->count();

        $created = $attached = $skipped = [];

        foreach (array_values(array_unique($properties)) as $property) {
            if (! $readable->has($property)) {
                $skipped[] = ['property' => $property, 'reason' => 'not_readable'];
                continue;
            }

            $domainKey = DomainNormalizer::hostKey(self::propertyDomain($property));
            $site = $existing->get($domainKey);

            if (! $site) {
                if ($used >= $limit) {
                    $skipped[] = ['property' => $property, 'reason' => 'site_limit_reached'];
                    continue;
                }

                $site = Site::withoutGlobalScope('organization')->create([
                    'organization_id'     => $org->id,
                    'name'                => self::propertyDomain($property),
                    'domain'              => self::propertyDomain($property),
                    'normalized_domain'   => $domainKey,
                    'canonical_url'       => self::propertyUrl($property),
                    'platform'            => 'generic',
                    'status'              => 'active',
                    'connection_status'   => 'pending',
                    'timezone'            => 'UTC',
                ]);
                $existing->put($domainKey, $site);
                $used++;
                $created[] = $this->attachAndSync($site, $account, $property, $userId, true);
            } else {
                $attached[] = $this->attachAndSync($site, $account, $property, $userId, false);
            }
        }

        return ['created' => $created, 'attached' => $attached, 'skipped' => $skipped];
    }

    /** Attach GSC (org grant, no consent) + select the property + queue sync. */
    private function attachAndSync(Site $site, ProviderAccount $account, string $property, int $userId, bool $isNew): array
    {
        $credential = $this->accounts->attachGoogleSource($site, 'gsc', $account, $userId);
        $credential->resource_json = ['id' => $property, 'name' => $property];
        $credential->save();

        // The selected property is what makes the source usable — record it
        // connected, with access metadata (spec §7: access ≠ ownership).
        \App\Models\DataSourceConnection::recordAttempt($site->organization_id, $site->id, 'gsc', [
            'state'             => 'connected',
            'connection_method' => 'oauth',
            'label'             => $account->email,
            'metadata_json'     => [
                'access_verified'   => true,
                'access_method'     => 'gsc',
                'provider_permission' => 'siteOwner',
                'gsc_property'      => $property,
            ],
            'last_error_code'    => null,
            'last_error_message' => null,
        ]);

        // Initial window now; full history in the background.
        SyncDataSource::dispatch($site->id, 'gsc', 30);
        Queue::push(new BackfillGscHistory($site, 'query', now()->subMonths(16)->toDateString(), now()->subDay()->toDateString()));
        Queue::push(new BackfillGscHistory($site, 'page', now()->subMonths(16)->toDateString(), now()->subDay()->toDateString()));

        if ($isNew) {
            $this->kickoff->start($site); // crawler + uptime + pagespeed + platform detect
        }

        return [
            'site_id'  => $site->id,
            'name'     => $site->name,
            'domain'   => $site->normalized_domain,
            'property' => $property,
            'is_new'   => $isNew,
        ];
    }

    /** "sc-domain:example.com" or "https://example.com/path" → "example.com/path"-free domain. */
    public static function propertyDomain(string $property): string
    {
        if (str_starts_with($property, 'sc-domain:')) {
            return substr($property, strlen('sc-domain:'));
        }

        $parts = parse_url($property);

        return strtolower($parts['host'] ?? $property);
    }

    /** Canonical root URL for a property (scheme + host only, path dropped). */
    public static function propertyUrl(string $property): string
    {
        if (str_starts_with($property, 'sc-domain:')) {
            return 'https://' . self::propertyDomain($property);
        }

        $parts = parse_url($property);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return 'https://' . self::propertyDomain($property);
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
    }
}
