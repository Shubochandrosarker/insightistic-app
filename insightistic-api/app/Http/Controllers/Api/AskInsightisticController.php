<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Query\QueryEngineException;
use App\Services\Ask\AskInsightisticService;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * HTTP surface for Ask Insightistic V2 (spec: P3) — a free-text question in,
 * a cited, confidence-scored answer out. Site scope comes only from the
 * `site.access` middleware-resolved route model, exactly like
 * AnalyticsQueryController; a question can never widen its own tenant
 * scope, only choose among what QuestionPlanBuilder is allowed to build.
 */
class AskInsightisticController extends Controller
{
    public function __construct(private readonly AskInsightisticService $service) {}

    public function ask(Request $request, Tenancy $tenancy)
    {
        $site = $request->attributes->get('site');
        /** @var Site $site */
        $question = $request->input('question');
        $userId = $request->user()?->id;

        if (! is_string($question) || trim($question) === '') {
            return response()->json(['message' => 'question is required.', 'code' => 'invalid_question'], 422);
        }

        try {
            $result = $this->service->ask($question, $site, $tenancy->organization()->id, $userId);
        } catch (QueryEngineException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $this->statusFor($e->errorCode));
        }

        return response()->json($result);
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            'concurrency_limit_exceeded' => 429,
            'timeout' => 504,
            'read_only_identity_not_configured' => 503,
            'question_not_understood' => 422,
            default => 422,
        };
    }
}
