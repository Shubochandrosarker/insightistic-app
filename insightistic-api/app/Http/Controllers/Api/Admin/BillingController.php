<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Billing\CreditLedger;
use App\Domain\Billing\CreditWallet;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Super-admin read/write surface for commercial operations and audit review. */
class BillingController extends AdminController
{
    public function organizationCredits(int $organizationId, CreditWallet $wallet)
    {
        $org = Organization::findOrFail($organizationId);
        return response()->json(['organization_id' => $org->id, 'wallet' => $wallet->summary($org), 'transactions' => $wallet->transactions($org)]);
    }

    public function adjustCredits(Request $request, int $organizationId, CreditLedger $ledger)
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'between:1,100000000'],
            'reason' => ['required', 'string', 'max:190'],
            'action' => ['required', 'in:grant,revoke'],
        ]);
        $org = Organization::findOrFail($organizationId);
        $reference = 'admin:' . $request->user()->id . ':' . Str::uuid();
        $entry = $data['action'] === 'grant'
            ? $ledger->grant($org, $data['amount'], $data['reason'], 'admin_adjustment', $reference, 'purchased', null, null, ['admin_id' => $request->user()->id, 'adjustment' => 'manual'])
            : $ledger->consumeBucket($org, $data['amount'], $data['reason'], 'admin_revoke', $reference, 'purchased');

        return response()->json(['entry' => $entry], 201);
    }
}
