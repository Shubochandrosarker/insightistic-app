<?php

namespace App\Models;

use App\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

class BrandSettings extends Model
{
    use BelongsToOrganization;

    protected $table = 'brand_settings';

    protected $fillable = [
        'organization_id', 'logo_url', 'primary_color', 'accent_color',
        'custom_domain', 'email_from_name', 'email_from_address', 'report_footer_text',
    ];
}
