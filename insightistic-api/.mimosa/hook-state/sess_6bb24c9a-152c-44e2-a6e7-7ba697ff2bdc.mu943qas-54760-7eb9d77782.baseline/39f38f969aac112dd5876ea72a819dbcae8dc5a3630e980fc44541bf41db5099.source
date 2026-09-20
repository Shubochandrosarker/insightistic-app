<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class CreditLedgerEntry extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id', 'amount_signed', 'reason',
        'source_type', 'source_reference', 'balance_after',
    ];

    protected function casts(): array
    {
        return [
            'amount_signed' => 'integer',
            'balance_after' => 'integer',
        ];
    }
}
