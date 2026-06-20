<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WcOrderItem extends Model
{
    protected $table = 'wc_order_items';

    protected $fillable = [
        'site_id', 'external_order_id', 'external_product_id',
        'product_name', 'sku', 'quantity', 'subtotal', 'total',
    ];
}
