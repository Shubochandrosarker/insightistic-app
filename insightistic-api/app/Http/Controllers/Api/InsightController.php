<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiInsight;
use App\Models\Site;
use App\Services\ContextAssembler;
use App\Services\InsightGenerator;
use App\Services\UsageService;
use App\Support\DateRange;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InsightController extends Controller
{
    private const LEGACY_TYPES = ['daily', 'weekly', 'monthly'];

    public function __construct(
        private InsightGenerator $generator,
        private ContextAssembler $contexts,
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
            'type'   => ['required', Rule::in(array_merge(self::LEGACY_TYPES, ContextAssembler::TYPES))],
            'period' => ['nullable', 'string'],
            'from'   => ['nullable', 'date'],
            'to'     => ['nullable', 'date'],
            'focus'  => ['nullable', 'string', 'max:50'],
        ]);

        $isLegacy = in_array($data['type'], self::LEGACY_TYPES, true);
        $range = $isLegacy
            ? $this->rangeForType($data['type'], $site)
            : DateRange::fromRequest($data['period'] ?? 'last_30_days', $data['from'] ?? null, $data['to'] ?? null, $site->timezone ?: config('app.timezone', 'UTC'));

        // Assembling context is cheap (a few indexed reads) and doesn't touch
        // the AI provider — check for evidence BEFORE metering usage, so a
        // site with no traffic/SEO/performance data yet (Milestone 3's
        // connector v2 isn't built) gets a clear "not enough data" response
        // instead of silently burning a quota slot on every attempt.
        $context = $isLegacy ? null : $this->contexts->assemble($data['type'], $site, $range, $data['focus'] ?? null);

        if ($context !== null && ! $context['has_data']) {
            return response()->json([
                'insight' => null,
                'code'    => 'no_data_yet',
                'message' => 'Not enough data yet to generate this insight — check back once this site has synced more history.',
            ]);
        }

        if (! $this->usage->canGenerateInsight($org)) {
            return response()->json([
                'message' => 'AI insight limit reached for this month. Upgrade your plan for more.',
                'code'    => 'ai_limit_reached',
            ], 402);
        }

        $insight = $isLegacy
            ? $this->generator->generate($site, $range, $data['type'])
            : $this->generator->generateContextual($site, $data['type'], $context);

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
