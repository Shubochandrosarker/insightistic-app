<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SiteUserAccess extends Model
{
    protected $table = 'site_user_access';

    protected $fillable = ['site_id', 'user_id', 'role'];
}
