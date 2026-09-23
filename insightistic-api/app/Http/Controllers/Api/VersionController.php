<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/** Public release fingerprint used by deploy smoke tests and support. */
class VersionController extends Controller
{
    public function __invoke()
    {
        return response()->json([
            'release'     => (string) config('insightistic.release.sha', 'unknown'),
            'built_at'    => config('insightistic.release.built_at'),
            'environment' => app()->environment(),
        ]);
    }
}
