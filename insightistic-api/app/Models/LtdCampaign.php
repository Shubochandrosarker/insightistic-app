<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LtdCampaign extends Model
{
    protected $fillable = [
        'slug', 'name', 'maximum_sales', 'successful_sales', 'state',
        'opened_at', 'closed_at', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'maximum_sales' => 'integer', 'successful_sales' => 'integer',
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(LtdCheckoutReservation::class, 'campaign_id');
    }
}
