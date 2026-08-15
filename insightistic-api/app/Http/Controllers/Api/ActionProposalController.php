<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActionProposal;
use App\Models\Site;
use App\Services\Actions\ActionProposalService;
use App\Services\Actions\ActionType;
use Illuminate\Http\Request;

/**
 * HTTP surface for Bridgistic Approved Actions (spec: P9). Every mutating
 * endpoint here that would execute something (`approve`) requires the
 * authenticated caller to BE the reviewer — there is no "approve on
 * someone else's behalf" or service-account path. See
 * docs/BRIDGISTIC-APPROVED-ACTIONS.md.
 */
class ActionProposalController extends Controller
{
    public function __construct(private readonly ActionProposalService $service) {}

    /** GET /sites/{site}/action-proposals?status=pending|executed|failed|rejected */
    public function index(Request $request)
    {
        $site = $this->site($request);
        $query = ActionProposal::query()->where('site_id', $site->id)->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['action_proposals' => $query->limit(100)->get()]);
    }

    /** POST /sites/{site}/action-proposals — body: {action_type, action_params, rationale, source_type?, source_id?, evidence_run_ids?}. Creates a PENDING proposal only — never executes. */
    public function store(Request $request)
    {
        $site = $this->site($request);
        $userId = $request->user()?->id;

        $actionType = $request->input('action_type');
        if (! in_array($actionType, ActionType::ALL, true)) {
            return response()->json(['message' => 'action_type must be one of: '.implode(', ', ActionType::ALL).'.', 'code' => 'invalid_action_type'], 422);
        }

        $rationale = trim((string) $request->input('rationale', ''));
        if ($rationale === '') {
            return response()->json(['message' => 'rationale is required — explain why this action is being proposed.', 'code' => 'invalid_rationale'], 422);
        }

        $sourceType = $request->input('source_type', 'manual');
        if (! in_array($sourceType, ActionProposal::SOURCE_TYPES, true)) {
            return response()->json(['message' => 'source_type must be one of: '.implode(', ', ActionProposal::SOURCE_TYPES).'.', 'code' => 'invalid_source_type'], 422);
        }

        $actionParams = is_array($request->input('action_params')) ? $request->input('action_params') : [];
        // Proposals never carry a webhook secret — see ActionProposalService::approve().
        unset($actionParams['secret'], $actionParams['webhook_secret']);

        $proposal = $this->service->propose(
            $site,
            $actionType,
            $actionParams,
            $rationale,
            $userId,
            $sourceType,
            $request->input('source_id') ? (int) $request->input('source_id') : null,
            is_array($request->input('evidence_run_ids')) ? $request->input('evidence_run_ids') : [],
        );

        return response()->json(['action_proposal' => $proposal], 201);
    }

    public function show(Request $request, int $siteId, int $actionProposal)
    {
        $proposal = $this->find($request, $actionProposal);

        return response()->json(['action_proposal' => $proposal]);
    }

    /** POST .../approve — the ONLY path that ever executes a proposal. Requires the authenticated caller. */
    public function approve(Request $request, int $siteId, int $actionProposal)
    {
        $proposal = $this->find($request, $actionProposal);
        $site = $this->site($request);
        $userId = $request->user()?->id;

        if (! $proposal->isPending()) {
            return response()->json(['message' => "Only a pending proposal can be approved (current status: \"{$proposal->status}\").", 'code' => 'not_pending'], 422);
        }

        $approved = $this->service->approve($proposal, $site, $userId);

        return response()->json(['action_proposal' => $approved]);
    }

    public function reject(Request $request, int $siteId, int $actionProposal)
    {
        $proposal = $this->find($request, $actionProposal);
        $userId = $request->user()?->id;

        if (! $proposal->isPending()) {
            return response()->json(['message' => "Only a pending proposal can be rejected (current status: \"{$proposal->status}\").", 'code' => 'not_pending'], 422);
        }

        $rejected = $this->service->reject($proposal, $userId);

        return response()->json(['action_proposal' => $rejected]);
    }

    private function find(Request $request, int $id): ActionProposal
    {
        $site = $this->site($request);
        $proposal = ActionProposal::query()->where('site_id', $site->id)->find($id);

        abort_if(! $proposal, 404, 'Action proposal not found.');

        return $proposal;
    }

    private function site(Request $request): Site
    {
        return $request->attributes->get('site');
    }
}
