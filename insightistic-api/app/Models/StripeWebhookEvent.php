<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per Stripe event id we have accepted for processing.
 *
 * Not tenant-scoped: webhooks arrive with no authenticated organization, and
 * the ledger is a platform-level record rather than customer data.
 */
class StripeWebhookEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['stripe_event_id', 'type', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'datetime'];
    }
}
