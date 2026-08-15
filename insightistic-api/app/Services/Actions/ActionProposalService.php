<?php

namespace App\Services\Actions;

use App\Models\ActionProposal;
use App\Models\Site;
use App\Services\Outcome\OutcomeVerificationService;
use RuntimeException;

/**
 * Bridgistic Approved Actions (spec: P9) — every proposal ALWAYS requires
 * an explicit human approval before it ever executes. There is
 * deliberately no auto-approval path anywhere in this class: `approve()`
 * is the only method that ever calls `ActionExecutor`, and it always
 * requires a `$reviewerUserId` — a real, authenticated user clicking
 * "approve" is what triggers execution, never a rule, a schedule, or
 * another proposal. Contrast with P6's `AutomationRule`, which executes
 * automatically because a human already approved it *by configuring the
 * rule*; a proposal here requires that approval fresh, every single time.
 *
 * Both `propose()`/`approve()` and P6's `AutomationDispatcher::fire()`
 * call the exact same `ActionExecutor` for the exact same
 * `ActionType::ALL` allowlist — there is one implementation of what an
 * action type actually does.
 */
class ActionProposalService
{
    public function __construct(
        private readonly ActionExecutor $executor,
        private readonly OutcomeVerificationService $outcomeVerification,
    ) {}

    /**
     * @param  string[]  $evidenceRunIds  evidence_runs.id this proposal is backed by — never fabricated, always real evidence the caller already gathered (e.g. an Investigation's findings).
     */
    public function propose(
        Site $site,
        string $actionType,
        array $actionParams,
        string $rationale,
        ?int $proposedByUserId,
        string $sourceType = 'manual',
        ?int $sourceId = null,
        array $evidenceRunIds = [],
    ): ActionProposal {
        if (! in_array($actionType, ActionType::ALL, true)) {
            throw new \InvalidArgumentException("Unknown action type \"{$actionType}\".");
        }

        return ActionProposal::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'action_type' => $actionType,
            'action_params_json' => $actionParams,
            'rationale' => $rationale,
            'evidence_run_ids_json' => $evidenceRunIds,
            'status' => 'pending',
            'proposed_by_user_id' => $proposedByUserId,
        ]);
    }

    /**
     * The ONLY method in this class that ever executes an action. Approval
     * and execution happen together, synchronously — a proposal never sits
     * in an "approved but not yet run" limbo state.
     */
    public function approve(ActionProposal $proposal, Site $site, int $reviewerUserId): ActionProposal
    {
        if (! $proposal->isPending()) {
            throw new RuntimeException("Only a pending proposal can be approved (current status: \"{$proposal->status}\").");
        }

        // Proposals never carry a webhook secret (ActionProposal has no
        // encrypted-secret column the way AutomationRule does — see
        // docs/BRIDGISTIC-APPROVED-ACTIONS.md) — a send_webhook proposal
        // always delivers unsigned.
        $result = $this->executor->execute(
            $proposal->action_type,
            $proposal->action_params_json ?? [],
            $site,
            $reviewerUserId,
            webhookSecret: null,
        );

        $status = ($result['success'] ?? false) ? 'executed' : 'failed';

        $proposal->update([
            'status' => $status,
            'reviewed_by_user_id' => $reviewerUserId,
            'reviewed_at' => now(),
            'executed_at' => now(),
            'result_json' => $result,
        ]);

        $proposal = $proposal->fresh();

        if ($status === 'executed') {
            // Outcome Verification (P11) — capture a baseline reading NOW,
            // while the action's effect (if any) hasn't happened yet, so a
            // later verify() has something honest to compare against.
            // Never on a failed execution — there is nothing to verify the
            // outcome of an action that didn't actually run.
            $this->outcomeVerification->captureBaseline($proposal, $site);
        }

        return $proposal;
    }

    public function reject(ActionProposal $proposal, int $reviewerUserId): ActionProposal
    {
        if (! $proposal->isPending()) {
            throw new RuntimeException("Only a pending proposal can be rejected (current status: \"{$proposal->status}\").");
        }

        $proposal->update([
            'status' => 'rejected',
            'reviewed_by_user_id' => $reviewerUserId,
            'reviewed_at' => now(),
        ]);

        return $proposal->fresh();
    }
}
