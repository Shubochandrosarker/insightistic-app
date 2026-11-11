<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppSumoLicenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AppSumo OAuth redirect-URL handler (partner guide: OAuth/user login).
 *
 * After purchase AppSumo sends the buyer here with ?code=…; the code is
 * single-use. Flow: exchange code for an access token at the OpenID token
 * endpoint, read the license_key from the OpenID endpoint, fetch the full
 * license resource from the Licensing API v2, upsert it through the shared
 * AppSumoLicenseService, and hand the buyer to the SPA claim page.
 *
 * Any failure redirects to /pricing with appsumo_error=1 — the buyer keeps a
 * usable surface instead of a dead end. Without a `code` (Partner Portal
 * validation ping) the endpoint must answer 200 OK.
 */
class AppSumoOAuthController extends Controller
{
    /** Guide step 3 — the token identifies the buyer; this returns their license key. */
    private const OPENID_LICENSE_KEY_URL = 'https://appsumo.com/openid/license_key/';

    public function __construct(private AppSumoLicenseService $licenses) {}

    public function callback(Request $request)
    {
        // Validation ping: the registered redirect URL must answer 200 OK
        // before AppSumo issues OAuth credentials.
        if (! $request->query('code')) {
            return response()->json(['ok' => true, 'service' => 'appsumo-oauth']);
        }

        $licenseKey = null;

        try {
            $licenseKey = $this->claim($request);
        } catch (Throwable $e) {
            Log::error('AppSumo OAuth claim failed', ['error' => $e->getMessage()]);
        }

        if (! $licenseKey) {
            return redirect()->away($this->appUrl() . '/pricing?appsumo_error=1');
        }

        return redirect()->away($this->appUrl() . '/appsumo/claim?license=' . urlencode($licenseKey));
    }

    private function claim(Request $request): ?string
    {
        $oauth = (array) config('services.appsumo.oauth', []);

        if (empty($oauth['client_id']) || empty($oauth['client_secret'])) {
            return null;
        }

        // PKCE: when the authorization request used a verifier, the SPA passes
        // it through `state` as JSON — include it in the exchange.
        $state = json_decode((string) $request->query('state', ''), true);
        $verifier = is_array($state) && ! empty($state['code_verifier'])
            ? (string) $state['code_verifier']
            : null;

        $token = Http::asForm()->post((string) config('services.appsumo.token_url'), array_filter([
            'client_id'     => $oauth['client_id'],
            'client_secret' => $oauth['client_secret'],
            // Must match the Partner Portal redirect URL exactly (guide: 403 troubleshooting).
            'redirect_uri'  => $oauth['redirect'] ?: url('/api/appsumo/oauth/callback'),
            'grant_type'    => 'authorization_code',
            'code'          => (string) $request->query('code'),
            'code_verifier' => $verifier,
        ]));

        $accessToken = $token->json('access_token');
        if (! $token->successful() || ! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $keyed = Http::get(self::OPENID_LICENSE_KEY_URL, ['access_token' => $accessToken]);
        $licenseKey = $keyed->json('license_key');
        if (! $keyed->successful() || ! is_string($licenseKey) || $licenseKey === '') {
            return null;
        }

        $response = Http::withHeaders([
            'X-AppSumo-Licensing-Key' => (string) config('services.appsumo.key'),
        ])->get(rtrim((string) config('services.appsumo.base_url'), '/') . '/licenses/' . $licenseKey);

        if (! $response->successful()) {
            return null;
        }

        $body = (array) $response->json();

        // Normalize the API resource into the webhook payload shape so both
        // intakes share one upsert path. The OAuth visit IS the activation
        // step of the guide's purchase → webhook → OAuth sequence.
        $this->licenses->upsertFromPayload([
            'license_key'     => $licenseKey,
            'event'           => 'activate',
            'license_status'  => $body['status'] ?? 'inactive',
            'tier'            => isset($body['tier']) ? (int) $body['tier'] : null,
            'event_timestamp' => (int) (microtime(true) * 1000),
            'extra'           => ['reason' => 'OAuth license claim'],
            'test'            => false,
            'api_response'    => $body,
        ]);

        return $licenseKey;
    }

    private function appUrl(): string
    {
        return rtrim((string) config('insightistic.app_url', 'https://app.insightistic.com'), '/');
    }
}
