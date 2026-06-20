<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WcProduct extends Model
{
    protected $table = 'wc_products';

    protected $fillable = [
        'site_id', 'external_product_id', 'name', 'sku', 'price',
        'regular_price', 'sale_price', 'stock_quantity', 'stock_status',
        'total_sales', 'status', 'synced_at',
    ];

    protected function casts(): array
    {
        return ['synced_at' => 'datetime'];
    }
}
