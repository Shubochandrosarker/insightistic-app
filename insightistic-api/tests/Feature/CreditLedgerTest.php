<?php

use App\Domain\Billing\CreditLedger;
use App\Domain\Billing\Exceptions\InsufficientCreditsException;
use App\Models\CreditLedgerEntry;
use App\Models\Organization;

/**
 * CreditLedger idempotency: (source_type, source_reference) is the replay
 * key. Stripe webhooks and queued jobs retry in normal operation, and the
 * ledger must credit or deduct exactly once no matter how often they do.
 */

function ledgerOrg(string $email): Organization
{
    return makeOrgUser($email)[1];
}

it('grants credits and reports the running balance', function () {
    $org = ledgerOrg('credits-grant@example.com');
    $ledger = app(CreditLedger::class);

    $entry = $ledger->grant($org, 5000, 'credit pack purchase', 'stripe_transaction', 'txn_1');

    expect($ledger->balance($org))->toBe(5000)
        ->and((int) $entry->amount_signed)->toBe(5000)
        ->and((int) $entry->balance_after)->toBe(5000);
});

it('credits a replayed grant only once and returns the original entry', function () {
    $org = ledgerOrg('credits-grant-dup@example.com');
    $ledger = app(CreditLedger::class);

    $first = $ledger->grant($org, 5000, 'credit pack purchase', 'stripe_transaction', 'txn_dup');
    $replay = $ledger->grant($org, 5000, 'credit pack purchase', 'stripe_transaction', 'txn_dup');

    expect($ledger->balance($org))->toBe(5000)
        ->and($replay->id)->toBe($first->id)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->count())->toBe(1);
});

it('consumes credits and records balance_after', function () {
    $org = ledgerOrg('credits-consume@example.com');
    $ledger = app(CreditLedger::class);
    $ledger->grant($org, 5000, 'credit pack purchase', 'stripe_transaction', 'txn_c');

    $spent = $ledger->consume($org, 1500, 'ai visibility runs', 'usage_batch', 'batch_1');

    expect($ledger->balance($org))->toBe(3500)
        ->and((int) $spent->amount_signed)->toBe(-1500)
        ->and((int) $spent->balance_after)->toBe(3500);
});

it('rejects a consume larger than the balance and leaves the ledger untouched', function () {
    $org = ledgerOrg('credits-short@example.com');
    $ledger = app(CreditLedger::class);
    $ledger->grant($org, 100, 'credit pack purchase', 'stripe_transaction', 'txn_small');

    try {
        $ledger->consume($org, 500, 'overspend', 'usage_batch', 'batch_over');
        $this->fail('consume should have thrown InsufficientCreditsException');
    } catch (InsufficientCreditsException $exception) {
        expect($exception->expected)->toBe(500)
            ->and($exception->available)->toBe(100);
    }

    expect($ledger->balance($org))->toBe(100)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->count())->toBe(1);
});

it('deducts a replayed consume only once and returns the original entry', function () {
    $org = ledgerOrg('credits-consume-dup@example.com');
    $ledger = app(CreditLedger::class);
    $ledger->grant($org, 5000, 'credit pack purchase', 'stripe_transaction', 'txn_d');

    $first = $ledger->consume($org, 1000, 'serp checks', 'usage_batch', 'batch_dup');
    // Second attempt with the same source ref, after the balance moved on.
    $replay = $ledger->consume($org, 1000, 'serp checks', 'usage_batch', 'batch_dup');

    expect($ledger->balance($org))->toBe(4000)
        ->and($replay->id)->toBe($first->id)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->count())->toBe(2);
});

it('refuses zero or negative amounts on grant and consume', function () {
    $org = ledgerOrg('credits-zero@example.com');
    $ledger = app(CreditLedger::class);

    expect(fn () => $ledger->grant($org, 0, 'zero grant', 'stripe_transaction', 'txn_zero'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $ledger->grant($org, -5, 'negative grant', 'stripe_transaction', 'txn_neg'))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $ledger->consume($org, 0, 'zero consume', 'usage_batch', 'batch_zero'))
        ->toThrow(InvalidArgumentException::class);

    expect($ledger->balance($org))->toBe(0)
        ->and(CreditLedgerEntry::withoutGlobalScope('organization')->count())->toBe(0);
});
