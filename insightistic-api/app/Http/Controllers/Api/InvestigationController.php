<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Investigation;
use App\Models\Site;
use App\Services\Investigation\InvestigationService;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/**
 * HTTP surface for WPAgentistic Investigations (spec: P8) — a read-only,
 * multi-metric diagnostic run. See docs/WPAGENTISTIC-INVESTIGATIONS.md.
 */
class InvestigationController extends Controller
{
    public function __construct(private readonly InvestigationService $service) {}

    public function index(Request $request)
    {
        $site = $this->site($request);
        $investigations = Investigation::query()
            ->where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return response()->json(['investigations' => $investigations]);
    }

    public function store(Request $request, Tenancy $tenancy)
    {
        $site = $this->site($request);
        $userId = $request->user()?->id;

        $subject = trim((string) $request->input('subject', ''));
        if ($subject === '') {
            return response()->json(['message' => 'subject is required.', 'code' => 'invalid_subject'], 422);
        }

        $metricIds = $request->input('metric_ids');
        if (! is_array($metricIds) || $metricIds === []) {
            return response()->json(['message' => 'metric_ids must be a non-empty array.', 'code' => 'invalid_metric_ids'], 422);
        }

        $investigation = $this->service->investigate(
            $site,
            $tenancy->organization()->id,
            $subject,
            $metricIds,
            $userId,
        );

        return response()->json(['investigation' => $investigation], 201);
    }

    public function show(Request $request, int $siteId, int $investigation)
    {
        $row = $this->find($request, $investigation);

        return response()->json(['investigation' => $row]);
    }

    private function find(Request $request, int $id): Investigation
    {
        $site = $this->site($request);
        $row = Investigation::query()->where('site_id', $site->id)->find($id);

        abort_if(! $row, 404, 'Investigation not found.');

        return $row;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
