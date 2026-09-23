<?php

use App\Models\DataSourceCredential;
use App\Models\Organization;
use App\Models\ProviderAccount;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill (2026-09-23): seed org-level provider accounts from the freshest
 * existing per-site Google credential, and link every Google credential in
 * the org to it. Existing customers get org-level behavior (attach without
 * re-consent, single refresh path) with zero action on their side.
 *
 * Uses Eloquent (not DB::table) because tokens are encrypted at rest and
 * must be re-encrypted when copied into provider_accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orgIds = DataSourceCredential::withoutGlobalScope('organization')
            ->where('provider', 'google')
            ->whereNull('provider_account_id')
            ->distinct()
            ->pluck('organization_id');

        foreach ($orgIds as $orgId) {
            $org = Organization::find($orgId);
            if (! $org) {
                continue;
            }

            // Freshest Google credential in the org seeds the shared account.
            $seed = DataSourceCredential::withoutGlobalScope('organization')
                ->where('organization_id', $orgId)
                ->where('provider', 'google')
                ->orderByDesc('token_expires_at')
                ->orderByDesc('id')
                ->first();

            if (! $seed || ! $seed->refresh_token) {
                continue; // nothing useful to share — leave per-site tokens alone
            }

            $account = ProviderAccount::withoutGlobalScope('organization')->firstOrCreate(
                [
                    'organization_id'     => $orgId,
                    'provider'            => 'google',
                    'external_account_id' => $seed->external_account_id ?? ('legacy-' . $orgId),
                ],
                [
                    'email'                => $seed->external_account_id,
                    'display_name'         => $seed->external_account_id ?? 'Google account',
                    'access_token'         => $seed->access_token,
                    'refresh_token'        => $seed->refresh_token,
                    'token_expires_at'     => $seed->token_expires_at,
                    'scopes_json'          => $seed->scopes_json ?? [],
                    'connected_by_user_id' => $seed->connected_by_user_id,
                    'status'               => 'active',
                ],
            );

            DataSourceCredential::withoutGlobalScope('organization')
                ->where('organization_id', $orgId)
                ->where('provider', 'google')
                ->whereNull('provider_account_id')
                ->update(['provider_account_id' => $account->id]);
        }
    }

    public function down(): void
    {
        // Non-destructive: unlink credentials; the shared account rows are
        // harmless leftovers that the UI treats as "connected".
        DataSourceCredential::withoutGlobalScope('organization')
            ->whereNotNull('provider_account_id')
            ->update(['provider_account_id' => null]);
    }
};
