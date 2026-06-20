<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WcOrder extends Model
{
    protected $table = 'wc_orders';

    protected $fillable = [
        'site_id', 'external_order_id', 'order_number', 'customer_id', 'status',
        'currency', 'total', 'subtotal', 'tax_total', 'shipping_total',
        'discount_total', 'refund_total', 'payment_method',
        'created_at_store', 'completed_at_store', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at_store'   => 'datetime',
            'completed_at_store' => 'datetime',
            'synced_at'          => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
