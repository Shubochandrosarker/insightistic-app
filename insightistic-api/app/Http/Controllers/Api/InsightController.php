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

        // Dismissed insights are hidden by default but never deleted — a
        // dismissal is "not now", and the user can ask for them back.
        $filter = (string) $request->query('filter', 'live');

        $rows = AiInsight::query()
            ->where('site_id', $site->id)
            ->when($filter === 'live', fn ($q) => $q->whereNull('dismissed_at'))
            ->when($filter === 'saved', fn ($q) => $q->whereNotNull('saved_at'))
            ->when($filter === 'dismissed', fn ($q) => $q->whereNotNull('dismissed_at'))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'insights' => $rows->map(fn (AiInsight $i) => $this->summarise($i))->values(),
            'counts'   => [
                'live'      => AiInsight::where('site_id', $site->id)->whereNull('dismissed_at')->count(),
                'saved'     => AiInsight::where('site_id', $site->id)->whereNotNull('saved_at')->count(),
                'dismissed' => AiInsight::where('site_id', $site->id)->whereNotNull('dismissed_at')->count(),
            ],
        ]);
    }

    /*
     * Route parameters are injected positionally, so every method on a
     * `sites/{site}/insights/{insight}` route must declare {site} first even
     * though it reads the resolved Site off the request. Omitting it silently
     * binds the *site* id to $insight — which looks fine right up until a
     * tenant has an insight whose id differs from its site's.
     */

    /** POST /sites/{site}/insights/{insight}/save + DELETE to unsave. */
    public function save(Request $request, int $siteId, int $insight)
    {
        return $this->mutate($request, $insight, ['saved_at' => now()]);
    }

    public function unsave(Request $request, int $siteId, int $insight)
    {
        return $this->mutate($request, $insight, ['saved_at' => null]);
    }

    /** POST /sites/{site}/insights/{insight}/dismiss + DELETE to restore. */
    public function dismiss(Request $request, int $siteId, int $insight)
    {
        return $this->mutate($request, $insight, ['dismissed_at' => now()]);
    }

    public function restore(Request $request, int $siteId, int $insight)
    {
        return $this->mutate($request, $insight, ['dismissed_at' => null]);
    }

    /**
     * POST /sites/{site}/insights/{insight}/action
     *
     * Turns an insight into something that can be pasted into whatever
     * tracker the team already uses. Insightistic deliberately does not
     * invent its own task system — a half-built one nobody adopts is worse
     * than an export that drops cleanly into Linear, Jira or a doc.
     */
    public function action(Request $request, int $siteId, int $insight)
    {
        $row = $this->find($request, $insight);

        if (! $row) {
            return response()->json(['message' => 'Insight not found.'], 404);
        }

        $row->update(['actioned_at' => now(), 'status' => 'done']);

        $actions = $row->actions();
        $contract = $row->source_data_json ?? [];

        $markdown = "## {$row->title}\n\n"
            . ($row->summary ? "{$row->summary}\n\n" : '')
            . ($actions === [] ? '' : "### Recommended actions\n" . implode("\n", array_map(fn ($a) => "- [ ] {$a}", $actions)) . "\n\n")
            . (! empty($contract['why_it_may_matter']) ? "### Why it matters\n{$contract['why_it_may_matter']}\n\n" : '')
            . (! empty($contract['data_limitations']) ? "> Data limitations: {$contract['data_limitations']}\n" : '');

        return response()->json([
            'task' => [
                'title'       => $row->title,
                'description' => $row->summary,
                'actions'     => $actions,
                'severity'    => $row->severity,
                'source'      => 'Insightistic insight #' . $row->id,
                'markdown'    => $markdown,
            ],
            'insight' => $this->summarise($row->fresh()),
        ]);
    }

    private function mutate(Request $request, int $insight, array $attrs)
    {
        $row = $this->find($request, $insight);

        if (! $row) {
            return response()->json(['message' => 'Insight not found.'], 404);
        }

        $row->update($attrs);

        return response()->json(['insight' => $this->summarise($row->fresh())]);
    }

    private function find(Request $request, int $insight): ?AiInsight
    {
        return AiInsight::where('site_id', $this->site($request)->id)->find($insight);
    }

    /** List shape, including the lifecycle flags the dashboard renders. */
    private function summarise(AiInsight $i): array
    {
        return [
            'id'             => $i->id,
            'type'           => $i->type,
            'title'          => $i->title,
            'summary'        => $i->summary,
            'recommendation' => $i->recommendation,
            'severity'       => $i->severity,
            'priority_score' => $i->priority_score,
            'status'         => $i->status,
            'saved'          => $i->saved_at !== null,
            'dismissed'      => $i->dismissed_at !== null,
            'actioned'       => $i->actioned_at !== null,
            'stale'          => $i->isStale(),
            'expires_at'     => $i->expires_at?->toIso8601String(),
            'created_at'     => $i->created_at?->toIso8601String(),
        ];
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

    public function show(Request $request, int $siteId, int $insight)
    {
        $site = $this->site($request);
        $row = AiInsight::where('site_id', $site->id)->find($insight);

        if (! $row) {
            return response()->json(['message' => 'Insight not found.'], 404);
        }

        return response()->json(['insight' => $row]);
    }

    public function markRead(Request $request, int $siteId, int $insight)
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
