<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\License;
use App\Models\LicenseActivation;
use App\Services\LicenseService;
use App\Services\LifecycleMailer;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * Dashboard Licenses tab (spec §2 Licenses). Raw keys appear exactly once —
 * in the regenerate response (and originally in the register response) —
 * everything else is masked.
 */
class LicenseController extends Controller
{
    public function __construct(
        private LicenseService $licenses,
        private LifecycleMailer $mailer,
        private Tenancy $tenancy,
    ) {}

    public function index()
    {
        $org = $this->tenancy->organization()->load(['plan', 'subscription']);

        $items = License::with('plan')->latest()->get()->map(function (License $license) use ($org) {
            // Keep the persisted status honest on every read.
            $computed = $this->licenses->computeStatus($org, $license);
            if ($computed !== $license->status) {
                $license->status = $computed;
                $license->save();
            }

            return [
                'id'                => $license->id,
                'masked_key'        => $license->maskedKey(),
                'last4'             => $license->license_key_last4,
                'status'            => $license->status,
                'plan'              => $license->plan?->only(['slug', 'name', 'site_limit']),
                'activation_limit'  => (int) $license->activation_limit,
                'activations_used'  => $license->countedActivations(),
                'features'          => $this->licenses->entitlements($license)['features'],
                'created_at'        => $license->created_at?->toIso8601String(),
                'last_validated_at' => $license->last_validated_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'licenses'      => $items,
            'trial_ends_at' => $org->trial_ends_at?->toIso8601String(),
            'renewal_at'    => $org->subscription?->current_period_end?->toIso8601String(),
        ]);
    }

    /** New key, same license. Old key dies instantly; connected sites keep syncing. */
    public function regenerate(Request $request, License $license)
    {
        $raw = $this->licenses->regenerate($license);

        $this->mailer->send(
            $this->tenancy->organization(),
            'license_regenerated',
            [],
            'regen:' . now()->format('YmdHis'),
        );

        return response()->json([
            'license_key' => $raw,
            'last4'       => substr($raw, -4),
            'notice'      => 'Copy this key now — it will not be shown again. The old key has stopped working.',
        ]);
    }

    public function activations(License $license)
    {
        $rows = $license->activations()
            ->with('site:id,name,domain,connection_status,last_sync_at')
            ->orderByDesc('activated_at')
            ->get()
            ->map(fn (LicenseActivation $a) => [
                'id'                 => $a->id,
                'site_id'            => $a->site_id,
                'site_url'           => $a->normalized_site_url,
                'site_name'          => $a->site?->name,
                'environment'        => $a->environment,
                'status'             => $a->status,
                'wp_version'         => $a->wp_version,
                'plugin_version'     => $a->plugin_version,
                'woocommerce_active' => $a->woocommerce_active,
                'connection_status'  => $a->site?->connection_status,
                'last_sync_at'       => $a->site?->last_sync_at?->toIso8601String(),
                'activated_at'       => $a->activated_at?->toIso8601String(),
                'last_seen_at'       => $a->last_seen_at?->toIso8601String(),
            ]);

        return response()->json(['activations' => $rows]);
    }

    /** "Deactivate website" — frees the slot; the plugin drops to free mode on next check-in. */
    public function deactivateActivation(LicenseActivation $activation)
    {
        // Tenant safety: the activation must belong to one of this org's licenses.
        $ownsIt = License::whereKey($activation->license_id)->exists();
        if (! $ownsIt) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $activation->update(['status' => 'deactivated', 'deactivated_at' => now()]);
        $activation->site?->update(['connection_status' => 'disconnected']);

        return response()->json(['ok' => true]);
    }
}
