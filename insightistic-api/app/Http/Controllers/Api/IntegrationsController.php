<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDataSource;
use App\Models\DataSourceConnection;
use App\Models\DataSourceCredential;
use App\Models\PerformanceRun;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Models\User;
use App\Services\Integrations\GoogleOAuthService;
use App\Services\Integrations\IntegrationFailure;
use App\Services\Integrations\IntegrationManager;
use App\Services\Integrations\ProviderAccountManager;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Data-source health (spec §7.4, §10.6) plus the direct connection workflow
 * for GA4, Search Console and Cloudflare (spec Phase 3.2).
 *
 * Platform-native sources (crawler, uptime and PageSpeed) are read-only here;
 * they are available without a connector. WordPress/WooCommerce remain
 * optional plugin-fed extensions.
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
        private readonly ProviderAccountManager $providerAccounts,
        private readonly \App\Services\Integrations\GscImportService $gscImport,
        private readonly \App\Services\Integrations\Ga4Client $ga4,
    ) {
    }

    // ---- Org-level Google account (spec §5/§6) ------------------------------

    /** GET /integrations/google/account */
    public function googleAccountStatus(Request $request, Tenancy $tenancy)
    {
        return response()->json(
            $this->providerAccounts->googleStatus($tenancy->organization()),
        );
    }

    /** POST /integrations/google/account/authorize-url — ONE org-wide consent. */
    public function googleAccountAuthorizeUrl(Request $request, Tenancy $tenancy)
    {
        try {
            $url = $this->google->orgAuthorizeUrl(
                $tenancy->organization()->id,
                (int) $request->user()->id,
            );
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }

        return response()->json(['authorize_url' => $url]);
    }

    /** DELETE /integrations/google/account */
    public function googleAccountDisconnect(Request $request, Tenancy $tenancy)
    {
        $account = $this->providerAccounts->googleAccount($tenancy->organization());

        if (! $account) {
            return response()->json(['message' => 'No Google account connected.', 'code' => 'not_connected'], 409);
        }

        $this->providerAccounts->disconnectGoogle($account);

        return response()->json(['status' => 'disconnected']);
    }

    // ---- GSC property import (spec §7) — org-level, plugin-free ----------

    /** GET /integrations/google/gsc/properties */
    public function gscProperties(Request $request, Tenancy $tenancy)
    {
        try {
            return response()->json([
                'properties' => $this->gscImport->availableProperties($tenancy->organization()),
            ]);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }
    }

    /** POST /integrations/google/gsc/import  { properties: string[] } */
    public function gscImport(Request $request, Tenancy $tenancy)
    {
        $data = $request->validate([
            'properties'   => ['required', 'array', 'min:1', 'max:100'],
            'properties.*' => ['string', 'max:500'],
        ]);

        try {
            $result = $this->gscImport->import(
                $tenancy->organization(),
                $data['properties'],
                (int) $request->user()->id,
            );
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }

        return response()->json($result, 201);
    }

    /** GET /integrations/google/ga4/properties — org-level GA4 picker. */
    public function ga4Properties(Request $request, Tenancy $tenancy)
    {
        $account = $this->providerAccounts->googleAccount($tenancy->organization());

        if (! $account) {
            return response()->json(['message' => 'Connect Google for your organization first.', 'code' => 'org_google_not_connected'], 409);
        }

        try {
            return response()->json([
                'properties' => $this->ga4->listResourcesForAccount($account),
            ]);
        } catch (IntegrationFailure $e) {
            return $this->failure($e);
        }
    }

    /**
     * POST /sites/{site}/integrations/{source}/attach
     *
     * Attach gsc/ga4 to a site from the org's shared Google grant — no new
     * consent needed. Frontend calls this when the org Google account exists.
     */
    public function attach(Request $request, string $siteId, string $source)
    {
        $site = $this->site($request);
        $this->assertGoogleSource($source);

        $account = $this->providerAccounts->googleAccount($site->organization);

        if (! $account) {
            return response()->json([
                'message' => 'Connect Google for your organization first.',
                'code'    => 'org_google_not_connected',
            ], 409);
        }

        $credential = $this->providerAccounts->attachGoogleSource(
            $site, $source, $account, (int) $request->user()->id,
        );

        return response()->json([
            'status'     => 'attached',
            'needs_resource' => empty($credential->resource_json['id']),
        ]);
    }

    /** GET /sites/{site}/integrations */
    public function index(Request $request)
    {
        $site = $this->site($request);

        $existing = DataSourceConnection::where('site_id', $site->id)->get()->keyBy('source');

        // Dual-path sources (spec §12): the SAME data can arrive via the
        // WordPress plugin AND via a direct OAuth connection — the UI must
        // be able to show both states at once.
        $orgGoogle = $this->providerAccounts->googleAccount($site->organization);

        $sources = collect(DataSourceConnection::SOURCES)->map(function (string $source) use ($existing, $site, $orgGoogle) {
            $row = $existing->get($source);
            $direct = in_array($source, DataSourceCredential::DIRECT_SOURCES, true);
            $builtIn = in_array($source, DataSourceConnection::BUILT_IN_SOURCES, true);
            $dualPath = in_array($source, ['gsc', 'ga4'], true);

            $builtInState = $row?->state ?? 'not_configured';
            $builtInLastDataAt = $row?->latest_data_at?->toIso8601String();
            if ($source === 'pagespeed') {
                $latest = PerformanceRun::where('site_id', $site->id)->latest('run_at')->first();
                $builtInState = match ($latest?->status) {
                    'complete' => 'connected',
                    'queued', 'running' => 'syncing',
                    'failed' => 'error',
                    default => 'not_configured',
                };
                $builtInLastDataAt = $latest?->run_at?->toIso8601String();
            } elseif ($source === 'uptime') {
                $monitor = UptimeMonitor::where('site_id', $site->id)->first();
                $builtInState = $monitor === null ? 'not_configured' : ($monitor->active ? 'connected' : 'disabled');
                $builtInLastDataAt = $monitor?->last_checked_at?->toIso8601String();
            }

            $pluginFedAt = $row?->metadata_json['plugin_fed_at'] ?? null;
            $pluginActive = $row && (
                $row->connection_method === 'wordpress_plugin'
                || ($pluginFedAt !== null && \Illuminate\Support\Carbon::parse($pluginFedAt)->diffInHours(now()) < 48)
            );

            return [
                'source'             => $source,
                'state'              => $builtIn ? $builtInState : ($row?->state ?? 'not_configured'),
                'connection_method'  => $row?->connection_method,
                'label'              => $row?->label,
                'last_attempted_at'  => $row?->last_attempted_at?->toIso8601String(),
                'last_successful_at' => $row?->last_successful_at?->toIso8601String(),
                'latest_data_at'     => $builtIn ? $builtInLastDataAt : $row?->latest_data_at?->toIso8601String(),
                'freshness'          => $row?->freshness ?? 'unknown',
                'last_error_code'    => $row?->last_error_code,
                'last_error_message' => $row?->last_error_message,

                // Connection workflow metadata for the Integrations page.
                'managed_by_platform' => $builtIn,
                'connectable'        => $direct,
                'auth_type'          => $builtIn ? 'built_in' : ($direct ? match ($source) {
                    'cloudflare' => 'api_token',
                    'bing'       => 'api_key', // pasted key, no OAuth dance
                    default      => 'oauth',
                } : 'plugin'),
                'configured'         => $builtIn || ($direct ? $this->serverConfigured($source) : true),
                'connected'          => $builtIn ? $builtInState === 'connected' : ($direct && $this->manager->isConnected($site, $source)),
                'detail'             => $builtIn ? null : ($direct ? $this->manager->publicDetail($site, $source) : null),

                // Dual-path state (spec §12): "Connected via WordPress plugin"
                // vs "Direct Google connection: Not connected" — both at once.
                'plugin_fed'         => $builtIn ? null : ($dualPath ? [
                    'active'       => (bool) $pluginActive,
                    'last_data_at' => $pluginFedAt,
                ] : ($source === 'wordpress' || $source === 'woocommerce' || $source === 'pagespeed' || $source === 'engagement' || $source === 'email' ? [
                    'active'       => $pluginActive && in_array($row?->state ?? '', ['connected', 'syncing'], true),
                    'last_data_at' => $row?->latest_data_at?->toIso8601String(),
                ] : null)),

                // When the org has a shared Google grant, gsc/ga4 can be
                // attached to this site with no consent dance.
                'attachable_from_org' => ! $builtIn && $dualPath && $orgGoogle !== null && ! $this->manager->isConnected($site, $source),
            ];
        });

        return response()->json([
            'sources' => $sources->values(),
            'google_account' => $orgGoogle?->publicSummary(),
        ]);
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

        // Social login shares this Google-registered redirect URI (see
        // config/services.php). A login state nonce means this is a sign-in,
        // not a data-source connect — hand the whole flow to OAuthController.
        if (OAuthController::isLoginState((string) $request->query('state', ''), 'google')) {
            return app(OAuthController::class)->callback('google', $request);
        }

        if ($request->query('error')) {
            return redirect($back . '?integration=error&reason=access_denied');
        }

        $payload = $this->google->consumeState((string) $request->query('state', ''));

        if (! $payload) {
            return redirect($back . '?integration=error&reason=invalid_state');
        }

        // Org-level flow (spec §5): one consent for the whole organization.
        if (($payload['flow'] ?? null) === 'org') {
            $org  = \App\Models\Organization::find($payload['org_id']);
            $user = User::find($payload['user_id']);

            if (! $org || ! $user || ! $user->roleIn($org)) {
                return redirect($back . '?integration=error&reason=forbidden');
            }

            try {
                $account = $this->providerAccounts->connectGoogle(
                    $org, (int) $user->id,
                    (string) $request->query('code', ''),
                    $payload['verifier'],
                );

                return redirect($back . '?integration=connected&google_account=' . urlencode((string) $account->email));
            } catch (IntegrationFailure $e) {
                return redirect($back . '?integration=error&reason=' . urlencode($e->errorCode));
            }
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
            $info   = $this->google->accountInfo($tokens['access_token']);
            $email  = $info['email'] ?? null;

            // Site-level consent now ALSO seeds the org provider account, so
            // the NEXT site skips the consent dance entirely (attach()).
            $account = ProviderAccountManager::upsertFromTokens(
                $site->organization, $tokens, $info, (int) $user->id,
            );

            $credential = $this->manager->storeGoogleGrant($site, $payload['source'], $tokens, $email, $user->id);
            // Link the credential to the shared account (refresh flows
            // through it from now on).
            $credential->provider_account_id = $account->id;
            $credential->refresh_token = null; // tokens live on the account
            $credential->save();
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

        // Funnel events (spec §33) — best-effort, never blocking.
        try {
            $events = ['gsc' => 'gsc_connected', 'ga4' => 'ga4_connected', 'cloudflare' => 'cloudflare_connected', 'bing' => 'bing_connected'];
            if ($event = $events[$source] ?? null) {
                app(\App\Services\Analytics\AttributionService::class)
                    ->event($event, $site->organization, $request->user(), [
                        'domain' => $site->normalized_domain, 'provider' => $source,
                    ]);
            }
        } catch (\Throwable) {
        }

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
        $status = in_array($e->errorCode, ['not_connected', 'no_property_selected', 'not_configured', 'reauth_required', 'org_google_not_connected'], true)
            ? 409
            : 502;

        return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $status);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
