<?php

/** Policy (Nov 2026): 30-day money-back is Lifetime Deal only; subscriptions and credit packs are non-refundable. */
it('exposes the lifetime-only refund policy on the billing payload', function () {
    [$user] = makeOrgUser('refund-policy@example.com', null, 'owner');
    $token = $user->createToken('t')->plainTextToken;

    $policy = $this->withToken($token)->getJson('/api/billing/subscription')->assertOk()->json('refund_policy');

    expect($policy['money_back_days'])->toBe(30)
        ->and($policy['applies_to'])->toBe(['lifetime'])
        ->and($policy['subscriptions_refundable'])->toBeFalse()
        ->and($policy['credit_packs_refundable'])->toBeFalse();
});
