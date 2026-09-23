<?php

namespace App\Jobs;

use App\Models\BillingTransaction;
use App\Models\Organization;
use App\Services\Analytics\ConversionDeliveryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverPurchaseConversions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    public int $tries = 5;

    public function __construct(public int $transactionId, public int $organizationId) {}

    public function handle(ConversionDeliveryService $delivery): void
    {
        $transaction = BillingTransaction::withoutGlobalScope('organization')->find($this->transactionId);
        $organization = Organization::find($this->organizationId);
        if ($transaction && $organization) {
            $delivery->deliver($transaction, $organization);
        }
    }
}
