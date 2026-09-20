<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDataSource;
use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\Site;
use App\Models\User;
use App\Services\Integrations\GoogleOAuthService;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Integrations\IntegrationManager;
use Illuminate\Http\Request;

/**
 * Data-source health (spec §7.4, §10.6) plus the direct connection workflow
 * for GA4, Search Console and Cloudflare (spec Phase 3.2).
 *
 * Sources the WordPress plugin feeds (wordpress, woocommerce, pagespeed,
 * engagement) stay read-only here — they are connected by installing the
 * plugin, not from this screen.
 *
 * SECURITY: no endpoint in this controller ever returns a token. The Google
 * consent redirect is minted server-side and the code exchange happens
 * server-to-server, so a refresh token never touches the browser.
 */
class IntegrationsController extends Controller
{
    public function __construct(
        private readonly IntegrationManager $manager,
        private readonly GoogleOAuthService $google,
    ) {
    }

    /** GET /sites/{site}/integrations */
    public function index(Request $request)
    {
        $site = $this->site($request);

        $existing = DataSourceConnection::where('site_id', $site->id)->get()->keyBy('source');

        $sources = collect(DataSourceConnection::SOURCES)->map(function (string $source) use ($existing, $site) {
            $row = $existing->get($source);
            $direct = in_array($source, DataSourceCredential::DIRECT_SOURCES, true);

            return [
                'source'             => $source,
                'state'              => $row->state ?? 'not_configured',
                'label'              => $row->label ?? null,
                'last_attempted_at'  => $row?->last_attempted_at?->toIso8601String(),
                'last_successful_at' => $row?->last_successful_at?->toIso8601String(),
                'latest_data_at'     => $row?->latest_data_at?->toIso8601String(),
                'freshness'          => $row->freshness ?? 'unknown',
                'last_error_code'    => $row->last_error_code ?? null,
                'last_error_message' => $row->last_error_message ?? null,

                // Connection workflow metadata for the Integrations page.
                'connectable'        => $direct,
                'auth_type'          => $direct ? match ($source) {
                    'cloudflare' => 'api_token',
                    'bing'       => 'api_key', // pasted key, no OAuth dance
                    default      => 'oauth',
                } : 'plugin',
                'configured'         => $direct ? $this->serverConfigured($source) : true,
                'connected'          => $direct && $this->manager->isConnected($site, $source),
                'detail'             => $direct ? $this->manager->publicDetail($site, $source) : null,
            ];
        });

        return response()->json(['sources' => $sources->values()]);
    }

    /**
     * POST /sites/{site}/integrations/{source}/authorize-url
     *
     * Returns the Google consent URL for the browser to follow. The state
     * nonce binds the eventual callback to this site, source and user.
     */
    public function authorizeUrl(Request $request, string $siteId, string $source)
    {
        $site = $this->site($request);
        $this->assertGoogleSource($source);

        try {
            $url = $this->google->authorizeUrl($site->id, $source, (int) $request->user()->id);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }

        return response()->json(['authorize_url' => $url]);
    }

    /**
     * GET /integrations/google/callback  (public — Google redirects here)
     *
     * Validates and burns the state, exchanges the code server-side, then
     * bounces the browser back to the app. No token is ever put in the
     * redirect URL, only a coarse status.
     */
    public function googleCallback(Request $request)
    {
        $app = rtrim((string) config('insightistic.app_url'), '/');
        $back = $app . '/dashboard/integrations';

        if ($request->query('error')) {
            return redirect($back . '?integration=error&reason=access_denied');
        }

        $payload = $this->google->consumeState((string) $request->query('state', ''));

        if (! $payload) {
            return redirect($back . '?integration=error&reason=invalid_state');
        }

        $site = Site::withoutGlobalScope('organization')->find($payload['site_id']);
        $user = User::find($payload['user_id']);

        // Re-check access at callback time: the user may have been removed
        // from the organization while the consent screen was open.
        if (! $site || ! $user || ! $user->roleIn($site->organization)) {
            return redirect($back . '?integration=error&reason=forbidden');
        }

        try {
            $tokens = $this->google->exchangeCode((string) $request->query('code', ''), $payload['verifier']);
            $email = $this->google->accountEmail($tokens['access_token']);

            $this->manager->storeGoogleGrant($site, $payload['source'], $tokens, $email, $user->id);
        } catch (IntegrationFailure $e) {
            return redirect($back . '?integration=error&reason=' . urlencode($e->errorCode));
        }

        return redirect($back . '?integration=connected&source=' . urlencode($payload['source']) . '&site=' . $site->id);
    }

    /** POST /sites/{site}/integrations/cloudflare/connect */
    public function connectCloudflare(Request $request)
    {
        $site = $this->site($request);
        $data = $request->validate(['api_token' => ['required', 'string', 'min:20', 'max:200']]);

        try {
            $this->manager->connectCloudflare($site, $data['api_token']);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }

        return response()->json(['status' => 'connected']);
    }

    /** POST /sites/{site}/integrations/bing/connect */
    public function connectBing(Request $request)
    {
        $site = $this->site($request);
        $data = $request->validate(['api_key' => ['required', 'string', 'min:16', 'max:200']]);

        try {
            $this->manager->connectBing($site, $data['api_key'], (int) $request->user()->id);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }

        return response()->json(['status' => 'connected']);
    }

    /** GET /sites/{site}/integrations/{source}/resources */
    public function resources(Request $request, string $siteId, string $source)
    {
        $site = $this->site($request);
        $this->assertDirectSource($source);

        try {
            return response()->json(['resources' => $this->manager->listResources($site, $source)]);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }
    }

    /** POST /sites/{site}/integrations/{source}/select */
    public function select(Request $request, string $siteId, string $source)
    {
        $site = $this->site($request);
        $this->assertDirectSource($source);

        $data = $request->validate([
            'id'         => ['required', 'string', 'max:500'],
            'name'       => ['nullable', 'string', 'max:255'],
            'account_id' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->manager->selectResource($site, $source, $data);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }

        // Selecting a property is the point the source becomes usable, so
        // pull an initial window immediately rather than waiting for tonight.
        SyncDataSource::dispatch($site->id, $source);

        return response()->json(['status' => 'selected']);
    }

    /** POST /sites/{site}/integrations/{source}/test */
    public function test(Request $request, string $siteId, string $source)
    {
        $site = $this->site($request);
        $this->assertDirectSource($source);

        try {
            return response()->json(['status' => 'ok', 'result' => $this->manager->test($site, $source)]);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }
    }

    /** POST /sites/{site}/integrations/{source}/resync */
    public function resync(Request $request, string $siteId, string $source)
    {
        $site = $this->site($request);
        $this->assertDirectSource($source);

        if (! $this->manager->isConnected($site, $source)) {
            return response()->json(['message' => 'Connect this integration before syncing.', 'code' => 'not_connected'], 422);
        }

        $days = (int) $request->integer('days', 30);
        SyncDataSource::dispatch($site->id, $source, max(1, min(365, $days)));

        return response()->json(['status' => 'queued']);
    }

    /** DELETE /sites/{site}/integrations/{source} */
    public function disconnect(Request $request, string $siteId, string $source)
    {
        $site = $this->site($request);
        $this->assertDirectSource($source);

        $this->manager->disconnect($site, $source);

        return response()->json(['status' => 'disconnected']);
    }

    private function serverConfigured(string $source): bool
    {
        // Cloudflare and Bing need no server-level credential — the user
        // supplies their own token/key at connect time.
        return in_array($source, ['cloudflare', 'bing'], true) ? true : $this->google->isConfigured();
    }

    private function assertDirectSource(string $source): void
    {
        if (! in_array($source, DataSourceCredential::DIRECT_SOURCES, true)) {
            abort(404);
        }
    }

    private function assertGoogleSource(string $source): void
    {
        if (! in_array($source, ['ga4', 'gsc'], true)) {
            abort(404);
        }
    }

    private function failure(IntegrationFailure $e)
    {
        // 409 for "you must do something first", 502 for provider problems —
        // the frontend renders the message verbatim either way.
        $status = in_array($e->errorCode, ['not_connected', 'no_property_selected', 'not_configured', 'reauth_required'], true)
            ? 409
            : 502;

        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $status);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
