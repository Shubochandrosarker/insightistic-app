<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Models\Site;
use App\Services\InsightGenerator;
use App\Services\UsageService;
use App\Support\DateRange;
use App\Support\Tenancy;
use Illuminate\Http\Request;

class InsightController extends Controller
{
    public function __construct(
        private InsightGenerator $generator,
        private UsageService $usage,
        private Tenancy $tenancy,
    ) {}

    public function index(Request $request)
    {
        $site = $this->site($request);

        return response()->json([
            'insights' => AiInsight::query()
                ->where('site_id', $site->id)
                ->latest()
                ->limit(50)
                ->get(['id', 'type', 'title', 'summary', 'recommendation', 'severity', 'priority_score', 'status', 'created_at']),
        ]);
    }

    public function generate(Request $request)
    {
        $site = $this->site($request);
        $org  = $this->tenancy->organization();

        $data = $request->validate([
            'type' => ['required', 'in:daily,weekly,monthly'],
        ]);

        if (! $this->usage->canGenerateInsight($org)) {
            return response()->json([
                'message' => 'AI insight limit reached for this month. Upgrade your plan for more.',
                'code'    => 'ai_limit_reached',
            ], 402);
        }

        $range = $this->rangeForType($data['type'], $site);
        $insight = $this->generator->generate($site, $range, $data['type']);

        if (! $insight) {
            return response()->json([
                'message' => 'AI provider is unavailable right now. No usage was charged.',
                'code'    => 'ai_unavailable',
            ], 502);
        }

        $this->usage->recordInsight($org);

        return response()->json([
            'insight'   => $insight,
            'remaining' => $this->usage->insightRemaining($org),
        ], 201);
    }

    public function show(Request $request, int $insight)
    {
        $site = $this->site($request);
        $row = AiInsight::where('site_id', $site->id)->find($insight);

        if (! $row) {
            return response()->json(['message' => 'Insight not found.'], 404);
        }

        return response()->json(['insight' => $row]);
    }

    public function markRead(Request $request, int $insight)
    {
        $site = $this->site($request);
        $row = AiInsight::where('site_id', $site->id)->find($insight);

        if (! $row) {
            return response()->json(['message' => 'Insight not found.'], 404);
        }

        $row->update(['status' => 'read']);

        return response()->json(['insight' => $row]);
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }

    private function rangeForType(string $type, Site $site): DateRange
    {
        $period = match ($type) {
            'daily'   => 'yesterday',
            'weekly'  => 'last_7_days',
            'monthly' => 'last_30_days',
        };

        return DateRange::fromRequest($period, null, null, $site->timezone ?: config('app.timezone', 'UTC'));
    }
}
