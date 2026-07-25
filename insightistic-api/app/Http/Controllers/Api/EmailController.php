<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailCampaign;
use App\Models\Site;
use App\Services\Email\EmailAnalyticsService;
use App\Services\Email\EmailProviderFailure;
use Illuminate\Http\Request;

/**
 * Email/campaign analytics (spec Phase 3.4).
 *
 * Replaces the previous placeholder Emails page: every figure here comes
 * from `email_campaigns`, written either by an API adapter (Mailchimp,
 * Klaviyo) or pushed by the WordPress connector (FluentCRM, AutomateWoo,
 * WooCommerce). When nothing is connected the endpoint says so explicitly
 * rather than returning zeroes that look like real measurements.
 *
 * SECURITY: the stored provider API key is never returned — only the
 * provider name and the non-secret account label it verified against.
 */
class EmailController extends Controller
{
    public function __construct(private readonly EmailAnalyticsService $emails)
    {
    }

    /** GET /sites/{site}/emails/overview */
    public function overview(Request $request)
    {
        $site = $this->site($request);
        $days = max(1, min(365, (int) $request->integer('days', 90)));

        $credential = $this->emails->credential($site);

        return response()->json([
            'connection' => [
                'connected'          => $credential !== null,
                'provider'           => $credential?->provider,
                'account'            => $credential?->external_account_id,
                'available_providers' => $this->emails->availableProviders(),
                // Providers that arrive through the plugin rather than an API key.
                'connector_providers' => EmailCampaign::CONNECTOR_PROVIDERS,
            ],
            'overview' => $this->emails->overview($site, $days),
        ]);
    }

    /** GET /sites/{site}/emails/campaigns */
    public function campaigns(Request $request)
    {
        $site = $this->site($request);

        $campaigns = EmailCampaign::where('site_id', $site->id)
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(max(1, min(200, (int) $request->integer('limit', 50))))
            ->get();

        return response()->json([
            'campaigns' => $campaigns->map(fn (EmailCampaign $c) => $c->toApiArray())->values(),
        ]);
    }

    /** POST /sites/{site}/emails/connect */
    public function connect(Request $request)
    {
        $site = $this->site($request);

        $data = $request->validate([
            'provider'   => ['required', 'string', 'in:' . implode(',', EmailCampaign::API_PROVIDERS)],
            'api_key'    => ['required', 'string', 'min:10', 'max:300'],
        ]);

        try {
            $account = $this->emails->connect($site, $data['provider'], $data['api_key'], (int) $request->user()->id);
        } catch (EmailProviderFailure $e) {
            return $this->failure($e);
        }

        return response()->json(['status' => 'connected', 'account' => $account]);
    }

    /** POST /sites/{site}/emails/sync */
    public function sync(Request $request)
    {
        $site = $this->site($request);

        try {
            return response()->json(['status' => 'ok', 'result' => $this->emails->sync($site)]);
        } catch (EmailProviderFailure $e) {
            return $this->failure($e);
        }
    }

    /** DELETE /sites/{site}/emails/connection */
    public function disconnect(Request $request)
    {
        $this->emails->disconnect($this->site($request));

        return response()->json(['status' => 'disconnected']);
    }

    private function failure(EmailProviderFailure $e)
    {
        $status = in_array($e->errorCode, ['not_connected', 'invalid_credential', 'unsupported_provider'], true) ? 409 : 502;

        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $status);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
