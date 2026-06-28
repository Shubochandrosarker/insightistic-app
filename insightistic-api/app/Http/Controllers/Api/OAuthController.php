<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\WelcomeMail;
use App\Models\User;
use App\Services\AccountProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Social login for Google, Microsoft and GitHub.
 *
 * Flow (stateless, SPA-friendly):
 *   1. Browser hits  GET /api/auth/oauth/{provider}/redirect  -> 302 to provider
 *   2. Provider calls GET /api/auth/oauth/{provider}/callback  with a code
 *   3. We find-or-create the user (+ bootstrap an org for new accounts),
 *      mint a Sanctum token, and 302 the browser to the SPA callback with the
 *      token in the URL fragment (never sent to a server / never logged).
 */
class OAuthController extends Controller
{
    /** Providers this build supports. */
    private const SUPPORTED = ['google', 'microsoft', 'github'];

    public function __construct(private AccountProvisioner $provisioner) {}

    /** Which providers are configured — drives which buttons the SPA shows. */
    public function providers(): JsonResponse
    {
        return response()->json(['providers' => $this->enabledProviders()]);
    }

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless($this->isEnabled($provider), 404, 'Provider not enabled.');

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider): RedirectResponse
    {
        $app = rtrim((string) config('insightistic.app_url'), '/');

        if (! $this->isEnabled($provider)) {
            return redirect($app . '/auth/callback#error=provider_unavailable');
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

        $user = User::where('provider', $provider)
            ->where('provider_id', (string) $oauthUser->getId())
            ->first()
            ?? User::where('email', $email)->first();

        if (! $user) {
            $isNew = true;
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

            $org = $this->provisioner->bootstrapOrganization($user, $this->deriveOrgName($oauthUser, $email));

            try {
                Mail::to($user->email)->send(new WelcomeMail($user, $org));
            } catch (\Throwable $e) {
                report($e);
            }
        } else {
            // Link the social identity onto an existing email/account, and make
            // sure the account is usable (has at least one organization).
            $user->forceFill([
                'provider'      => $user->provider ?: $provider,
                'provider_id'   => $user->provider_id ?: (string) $oauthUser->getId(),
                'avatar_url'    => $user->avatar_url ?: $oauthUser->getAvatar(),
                'status'        => $user->status === 'invited' ? 'active' : $user->status,
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
