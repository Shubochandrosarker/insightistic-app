<?php

namespace App\Http\Middleware;

use App\Models\Site;
use App\Services\Ai\AiAvailability;
use App\Support\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks only variable-cost AI actions for paused tenants/sites. */
class EnsureAiAvailable
{
    public function __construct(private readonly AiAvailability $availability) {}

    public function handle(Request $request, Closure $next): Response
    {
        $site = $request->attributes->get('site');
        if (! $site && $request->route('site')) {
            $site = Site::find($request->route('site'));
        }

        if ($this->availability->isPaused(app(Tenancy::class)->organization(), $site)) {
            return $this->availability->response();
        }

        return $next($request);
    }
}
