<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WcCustomer extends Model
{
    protected $table = 'wc_customers';

    protected $fillable = [
        'site_id', 'external_customer_id', 'email_hash', 'first_name', 'last_name',
        'city', 'country', 'total_spent', 'order_count',
        'first_order_at', 'last_order_at', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'first_order_at' => 'datetime',
            'last_order_at'  => 'datetime',
            'synced_at'      => 'datetime',
        ];
    }
}
