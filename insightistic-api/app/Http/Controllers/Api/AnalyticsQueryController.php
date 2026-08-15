<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Query\EvidenceLedger;
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
 *
 * Every execution — success or failure — is recorded to the Analytical
 * Evidence Ledger (spec: P2) via EvidenceLedger, independent of the engine
 * itself, which stays a pure computation with no storage side effect.
 */
class AnalyticsQueryController extends Controller
{
    public function __construct(
        private readonly SafeWooQueryEngine $engine,
        private readonly EvidenceLedger $ledger,
    ) {
    }

    public function query(Request $request, Tenancy $tenancy)
    {
        $site = $request->attributes->get('site');
        /** @var Site $site */
        $plan = $request->input('plan', []);
        $userId = $request->user()?->id;

        if (! is_array($plan)) {
            return response()->json(['message' => 'plan must be an object.', 'code' => 'invalid_plan'], 422);
        }

        try {
            $result = $this->engine->run($plan, $site, $tenancy->organization()->id);
        } catch (QueryEngineException $e) {
            $this->ledger->recordFailure($site, $userId, 'query', $plan, $e);

            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $this->statusFor($e->errorCode));
        }

        $run = $this->ledger->recordSuccess($site, $userId, 'query', $result);

        return response()->json([...$result->toArray(), 'evidence_run_id' => $run->id]);
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
