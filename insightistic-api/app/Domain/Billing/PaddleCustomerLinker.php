<?php

namespace App\Domain\Billing;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Keeps organizations.paddle_customer_id in step with Paddle. One Paddle
 * customer maps to one organization; an existing different link is never
 * overwritten silently (logged instead), so a shared card/email can't move
 * billing between tenants.
 */
final class PaddleCustomerLinker
{
    public function link(Organization $org, ?string $customerId): void
    {
        if (! $customerId || $org->paddle_customer_id === $customerId) {
            return;
        }
        $owner = Organization::where('paddle_customer_id', $customerId)->where('id', '!=', $org->id)->first();
        if ($owner) {
            Log::warning('Paddle customer already linked to another organization; not relinking.', [
                'customer_id' => $customerId, 'linked_org' => $owner->id, 'candidate_org' => $org->id,
            ]);

            return;
        }
        if ($org->paddle_customer_id && $org->paddle_customer_id !== $customerId) {
            Log::warning('Organization already has a different Paddle customer; keeping the first.', [
                'org' => $org->id, 'existing' => $org->paddle_customer_id, 'incoming' => $customerId,
            ]);

            return;
        }
        $org->forceFill(['paddle_customer_id' => $customerId])->save();
    }

    /**
     * customer.created / customer.updated: link only when unambiguous —
     * custom_data.organization_id, or an email that is the owner of exactly
     * one organization.
     */
    public function fromCustomerEvent(array $data): void
    {
        $customerId = $data['id'] ?? null;
        if (! $customerId || Organization::where('paddle_customer_id', $customerId)->exists()) {
            return;
        }
        $orgId = $data['custom_data']['organization_id'] ?? null;
        $org = $orgId ? Organization::find($orgId) : null;
        if (! $org && ($email = $data['email'] ?? null)) {
            $user = User::where('email', $email)->first();
            $orgs = $user ? Organization::where('owner_user_id', $user->id)->limit(2)->get() : collect();
            $org = $orgs->count() === 1 ? $orgs->first() : null;
        }
        if ($org) {
            $this->link($org, $customerId);
        }
    }
}
