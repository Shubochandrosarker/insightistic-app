<?php

namespace App\Http\Controllers\Api\Connector;

use App\Http\Controllers\Controller;
use App\Models\DataSourceConnection;
use App\Models\EmailCampaign;
use App\Models\Site;
use App\Models\SyncLog;
use App\Services\Email\EmailAnalyticsService;
use Illuminate\Http\Request;

/**
 * Connector ingestion for WordPress-side email platforms (spec Phase 3.4).
 *
 * FluentCRM, AutomateWoo and WooCommerce's own transactional mail live
 * inside the site, so the SaaS cannot pull them the way it pulls Mailchimp
 * or Klaviyo — the plugin pushes their figures here over the existing HMAC
 * contract instead.
 *
 * Idempotent: rows upsert on (site, provider, external_id) through the same
 * EmailAnalyticsService::store() the API adapters use, so a retried batch
 * converges rather than duplicating.
 */
class EmailSyncController extends Controller
{
    public function __construct(private readonly EmailAnalyticsService $emails)
    {
    }

    /** POST connector/v1/sync/email/campaigns */
    public function campaigns(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'provider'                    => ['required', 'string', 'in:' . implode(',', EmailCampaign::CONNECTOR_PROVIDERS)],
            'campaigns'                   => ['required', 'array', 'max:200'],
            'campaigns.*.external_id'     => ['required', 'string', 'max:191'],
            'campaigns.*.name'            => ['required', 'string', 'max:300'],
            'campaigns.*.subject'         => ['nullable', 'string', 'max:500'],
            'campaigns.*.type'            => ['nullable', 'string', 'in:campaign,automation,transactional'],
            'campaigns.*.status'          => ['nullable', 'string', 'in:draft,scheduled,sending,sent,paused'],
            'campaigns.*.sent_at'         => ['nullable', 'date'],
            'campaigns.*.recipients'      => ['nullable', 'integer', 'min:0'],
            'campaigns.*.sent'            => ['nullable', 'integer', 'min:0'],
            'campaigns.*.delivered'       => ['nullable', 'integer', 'min:0'],
            'campaigns.*.bounced'         => ['nullable', 'integer', 'min:0'],
            'campaigns.*.opened'          => ['nullable', 'integer', 'min:0'],
            'campaigns.*.unique_opens'    => ['nullable', 'integer', 'min:0'],
            'campaigns.*.clicked'         => ['nullable', 'integer', 'min:0'],
            'campaigns.*.unique_clicks'   => ['nullable', 'integer', 'min:0'],
            'campaigns.*.unsubscribed'    => ['nullable', 'integer', 'min:0'],
            'campaigns.*.complained'      => ['nullable', 'integer', 'min:0'],
            'campaigns.*.conversions'     => ['nullable', 'integer', 'min:0'],
            'campaigns.*.revenue'         => ['nullable', 'numeric', 'min:0'],
            'campaigns.*.currency'        => ['nullable', 'string', 'size:3'],
        ]);

        $rows = array_map(
            fn (array $c) => array_merge($c, ['provider' => $data['provider']]),
            $data['campaigns'],
        );

        $latest = $this->emails->store($site, $rows);

        // The plugin pushing email data *is* the connection for these
        // providers — there is no API key to verify, so arrival is the proof.
        DataSourceConnection::recordAttempt($site->organization_id, $site->id, 'email', [
            'state'              => 'connected',
            'connection_method'  => 'wordpress_plugin',
            'label'              => ucfirst($data['provider']),
            'last_attempted_at'  => now(),
            'last_successful_at' => now(),
            'latest_data_at'     => $latest,
            'freshness'          => 'fresh',
            'last_error_code'    => null,
            'last_error_message' => null,
        ]);

        SyncLog::create([
            'site_id'        => $site->id,
            'sync_batch_id'  => $request->header('X-INS-Sync-Batch') ?: $request->input('sync_batch_id'),
            'job'            => 'email_campaigns',
            'schema_version' => 'v2',
            'status'         => 'success',
            'records'        => count($rows),
            'started_at'     => now(),
            'finished_at'    => now(),
        ]);

        return response()->json(['status' => 'ok', 'received' => count($rows)]);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('connector_site');
    }
}
