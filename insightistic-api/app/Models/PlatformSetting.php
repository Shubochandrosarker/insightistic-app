<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value_json', 'updated_by'];

    protected function casts(): array
    {
        return ['value_json' => 'array'];
    }
}
