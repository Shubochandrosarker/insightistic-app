<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The IndexNow key for a site (one per site, docs/roadmap-2026/11 §IndexNow).
 *
 * The SaaS generates and stores the key; the WordPress plugin hosts the key
 * file at https://{domain}/{key}.txt and flips key_file_confirmed once it
 * serves it. Not affiliated with any credential store — this key is public
 * by design (it appears in the key file URL), so it is never hidden.
 */
class IndexNowKey extends Model
{
    protected $table = 'indexnow_keys';

    protected $fillable = ['site_id', 'key', 'key_file_confirmed'];

    protected function casts(): array
    {
        return [
            'key_file_confirmed' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The URL Bing/other engines fetch to verify ownership. */
    public function keyLocation(): string
    {
        return 'https://' . $this->site->domain . '/' . $this->key . '.txt';
    }
}
