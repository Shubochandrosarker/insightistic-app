<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Social login for Google, Microsoft and GitHub.
 *
 * Flow (SPA-friendly, no server session — CSRF protection via a cache-backed
 * single-use `state` nonce instead of Socialite's session state):
 *   1. Browser hits  GET /api/auth/oauth/{provider}/redirect  -> 302 to provider
 *      (we mint a random `state`, cache it for 10 minutes, single use)
 *   2. Provider calls GET /api/auth/oauth/{provider}/callback  with code+state
 *   3. We validate + burn the state, find-or-create the user (+ bootstrap an
 *      org for new accounts), mint a Sanctum token, and 302 the browser to the
 *      SPA callback with the token in the URL fragment.
 *
 * SECURITY INVARIANTS (tests in tests/Feature/OAuthSecurityTest.php):
 *   - No token is ever issued to a user whose status is not `active` or whose
 *     account is archived. Invited users must accept their invite; disabled /
 *     suspended / archived accounts are refused outright — OAuth must never
 *     be a side door around AuthController::login()'s checks.
 *   - OAuth NEVER grants super admin (see docs/SUPER-ADMIN.md).
 *   - A social identity is linked to an existing local account by email only
 *     when the provider asserts the email as verified AND the account has no
 *     other social identity bound. Otherwise the user is sent back with
 *     guidance to sign in with their password first.
 */
class OAuthController extends Controller
{
    /** Providers this build supports. */
    private const SUPPORTED = ['google', 'microsoft', 'github'];

    private const STATE_TTL_SECONDS = 600;

    public function __construct(private AccountProvisioner $provisioner) {}

    /** Which providers are configured — drives which buttons the SPA shows. */
    public function providers(): JsonResponse
    {
        return response()->json(['providers' => $this->enabledProviders()]);
    }

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless($this->isEnabled($provider), 404, 'Provider not enabled.');

        // Anti-CSRF `state`: random nonce, cached server-side, validated and
        // burned on callback. We manage it ourselves because the API issues
        // no session cookie for Socialite's built-in state to live in.
        $state = Str::random(40);
        Cache::put(self::stateCacheKey($state), $provider, self::STATE_TTL_SECONDS);

        return Socialite::driver($provider)
            ->stateless()
            ->with(['state' => $state])
            ->redirect();
    }

    public function callback(string $provider, Request $request): RedirectResponse
    {
        $app = rtrim((string) config('insightistic.app_url'), '/');

        if (! $this->isEnabled($provider)) {
            return redirect($app . '/auth/callback#error=provider_unavailable');
        }

        // Validate + burn the state nonce (single use). A missing or unknown
        // state means the flow didn't start on our redirect endpoint.
        $state = (string) $request->query('state', '');
        $cachedProvider = $state !== '' ? Cache::pull(self::stateCacheKey($state)) : null;
        if ($cachedProvider !== $provider) {
            Log::warning('OAuth callback rejected: invalid state', [
                'provider' => $provider,
                'has_state' => $state !== '',
                'provider_error' => (string) $request->query('error', ''),
            ]);

            return redirect($app . '/auth/callback#error=invalid_state');
        }

        // The user cancelled, or the provider refused the request (e.g. a
        // redirect_uri mismatch) — there is no code to exchange.
        if ($request->query('error')) {
            Log::warning('OAuth provider returned an error', [
                'provider' => $provider,
                'error' => (string) $request->query('error'),
                'description' => (string) $request->query('error_description', ''),
            ]);

            return redirect($app . '/auth/callback#error=access_denied');
        }

        try {
            $oauthUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            report($e);

            return redirect($app . '/auth/callback#error=oauth_failed');
        }

        $email = $oauthUser->getEmail();
        if (! $email) {
            return redirect($app . '/auth/callback#error=no_email');
        }

        $isNew = false;

        // Exact identity match first (provider + provider id) — always allowed.
        $user = User::where('provider', $provider)
            ->where('provider_id', (string) $oauthUser->getId())
            ->first();

        if (! $user) {
            // Fall back to email linking, but ONLY under the linking policy:
            // provider-verified email + no other social identity on the account.
            $byEmail = User::where('email', strtolower(trim($email)))->first();

            if ($byEmail) {
                if (! $this->providerEmailVerified($provider, $oauthUser)) {
                    return redirect($app . '/auth/callback#error=email_unverified');
                }
                if ($byEmail->provider && $byEmail->provider !== $provider) {
                    // Bound to a different provider — require an authenticated
                    // "connect account" flow rather than silent takeover.
                    return redirect($app . '/auth/callback#error=account_exists');
                }
                $user = $byEmail;
            }
        }

        if (! $user) {
            $isNew = true;

            // SECURITY: OAuth signup never grants super admin (docs/SUPER-ADMIN.md).
            $user = User::create([
                'name'              => $oauthUser->getName() ?: Str::before($email, '@'),
                'email'             => $email,
                'password'          => null,
                'status'            => 'active',
                'email_verified_at' => now(),
                'provider'          => $provider,
                'provider_id'       => (string) $oauthUser->getId(),
                'avatar_url'        => $oauthUser->getAvatar(),
                'last_login_at'     => now(),
            ]);

            $rawLicenseKey = null;
            $org = $this->provisioner->bootstrapOrganization($user, $this->deriveOrgName($oauthUser, $email), $rawLicenseKey);

            try {
                Mail::to($user->email)->send(new WelcomeMail($user, $org));
            } catch (\Throwable $e) {
                report($e);
            }
            try {
                // OAuth signups never see a register response, so the one-time
                // key travels in the trial-started email (masked everywhere else).
                app(\App\Services\LifecycleMailer::class)->send(
                    $org->fresh(['plan', 'owner']),
                    'trial_started',
                    ['license_key' => $rawLicenseKey],
                );
            } catch (\Throwable $e) {
                report($e);
            }
        } else {
            // SECURITY: enforce the same account-state rules as password login.
            // No token for invited (must accept invite), disabled, suspended,
            // archived or otherwise non-active accounts — and never silently
            // reactivate them here.
            if ($user->status === 'invited') {
                return redirect($app . '/auth/callback#error=invite_pending');
            }
            if ($user->status !== 'active' || $user->archived_at !== null) {
                return redirect($app . '/auth/callback#error=account_not_active');
            }

            $user->forceFill([
                'provider'      => $user->provider ?: $provider,
                'provider_id'   => $user->provider_id ?: (string) $oauthUser->getId(),
                'avatar_url'    => $user->avatar_url ?: $oauthUser->getAvatar(),
                'last_login_at' => now(),
            ])->save();

            if ($user->organizations()->count() === 0) {
                $this->provisioner->bootstrapOrganization($user, $this->deriveOrgName($oauthUser, $email));
            }
        }

        $token = $user->createToken('oauth')->plainTextToken;

        return redirect($app . '/auth/callback#token=' . urlencode($token) . ($isNew ? '&new=1' : ''));
    }

    // --- helpers -----------------------------------------------------------

    /** True when $state is a live social-login nonce for $provider (not burned). */
    public static function isLoginState(string $state, string $provider): bool
    {
        return $state !== '' && Cache::get(self::stateCacheKey($state)) === $provider;
    }

    private static function stateCacheKey(string $state): string
    {
        return 'oauth_state:' . hash('sha256', $state);
    }

    /**
     * Whether the provider asserts this email address as verified.
     *
     *  - google: explicit `email_verified` claim in the userinfo payload.
     *  - github: Socialite's GitHub provider resolves the email via
     *    /user/emails and only returns the primary VERIFIED address, so a
     *    non-null email is verified by construction.
     *  - microsoft: Graph only returns provisioned mailbox/UPN addresses for
     *    the authenticated account; treated as verified (documented policy).
     */
    private function providerEmailVerified(string $provider, \Laravel\Socialite\Contracts\User $oauthUser): bool
    {
        $raw = method_exists($oauthUser, 'getRaw') ? (array) $oauthUser->getRaw() : (array) ($oauthUser->user ?? []);

        return match ($provider) {
            'google'    => (bool) ($raw['email_verified'] ?? false),
            'github'    => $oauthUser->getEmail() !== null,
            'microsoft' => $oauthUser->getEmail() !== null,
            default     => false,
        };
    }

    private function enabledProviders(): array
    {
        return array_values(array_filter(self::SUPPORTED, fn ($p) => $this->isEnabled($p)));
    }

    private function isEnabled(string $provider): bool
    {
        if (! in_array($provider, self::SUPPORTED, true)) {
            return false;
        }

        return ! empty(config("services.$provider.client_id"))
            && ! empty(config("services.$provider.client_secret"));
    }

    private function deriveOrgName(\Laravel\Socialite\Contracts\User $oauthUser, string $email): string
    {
        if ($name = $oauthUser->getName()) {
            return Str::of($name)->trim()->limit(110, '')->value() . "'s workspace";
        }

        return Str::before($email, '@') . "'s workspace";
    }
}
