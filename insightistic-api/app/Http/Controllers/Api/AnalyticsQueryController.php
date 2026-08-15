<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Query\QueryEngineException;
use App\Query\SafeWooQueryEngine;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * HTTP surface for the Safe WooCommerce Query Engine (spec: P1). Accepts an
 * already-shaped semantic plan (from a human caller today; from an
 * AI-authored plan once P3 "Ask Insightistic V2" lands) and returns
 * normalized evidence. Site scope comes only from the `site.access`
 * middleware-resolved route model — never from the request body.
 */
class AnalyticsQueryController extends Controller
{
    public function __construct(private readonly SafeWooQueryEngine $engine) {}

    public function query(Request $request, Tenancy $tenancy)
    {
        $site = $request->attributes->get('site');
        /** @var Site $site */
        $plan = $request->input('plan', []);

        if (! is_array($plan)) {
            return response()->json(['message' => 'plan must be an object.', 'code' => 'invalid_plan'], 422);
        }

        try {
            $result = $this->engine->run($plan, $site, $tenancy->organization()->id);
        } catch (QueryEngineException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $this->statusFor($e->errorCode));
        }

        return response()->json($result->toArray());
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            'concurrency_limit_exceeded' => 429,
            'timeout' => 504,
            'read_only_identity_not_configured' => 503,
            default => 422,
        };
    }
}
