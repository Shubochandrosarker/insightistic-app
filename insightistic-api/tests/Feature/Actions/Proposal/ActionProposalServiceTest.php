<?php

use App\Models\ActionProposal;
use App\Models\Alert;
use App\Models\Organization;
use App\Models\Site;
use App\Services\Actions\ActionProposalService;
use App\Services\Actions\ActionType;

function proposalSite(Organization $org): Site
{
    return Site::withoutGlobalScope('organization')->create([
        'organization_id' => $org->id,
        'name' => 'Proposal Shop',
        'domain' => 'proposal-' . uniqid() . '.example.com',
        'connection_status' => 'connected',
        'timezone' => 'UTC',
    ]);
}

it('creates a proposal in pending status without executing anything', function () {
    [$user, $org] = makeOrgUser('proposal-create@example.com');
    $site = proposalSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);

    $proposal = app(ActionProposalService::class)->propose(
        $site, ActionType::RESOLVE_ALERT, ['alert_id' => $alert->id],
        'Revenue recovered per the latest investigation.', $user->id,
        'wpagentistic_investigation', 42, ['evidence_run:1'],
    );

    expect($proposal->status)->toBe('pending')
        ->and($proposal->executed_at)->toBeNull()
        ->and($proposal->reviewed_at)->toBeNull()
        ->and($alert->fresh()->is_resolved)->toBeFalse(); // NOT resolved — only proposed
});

it('rejects an unknown action type at proposal time', function () {
    [$user, $org] = makeOrgUser('proposal-bad-type@example.com');
    $site = proposalSite($org);

    app(ActionProposalService::class)->propose($site, 'delete_everything', [], 'x', $user->id);
})->throws(\InvalidArgumentException::class);

it('only executes the action once a human explicitly approves it', function () {
    [$user, $org] = makeOrgUser('proposal-approve@example.com');
    $site = proposalSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $service = app(ActionProposalService::class);
    $proposal = $service->propose($site, ActionType::RESOLVE_ALERT, ['alert_id' => $alert->id], 'x', $user->id);

    $approved = $service->approve($proposal, $site, $user->id);

    expect($approved->status)->toBe('executed')
        ->and($approved->reviewed_by_user_id)->toBe($user->id)
        ->and($approved->reviewed_at)->not->toBeNull()
        ->and($approved->executed_at)->not->toBeNull()
        ->and($approved->result_json['success'])->toBeTrue()
        ->and($alert->fresh()->is_resolved)->toBeTrue();
});

it('marks the proposal failed (not thrown) when the underlying action itself fails', function () {
    [$user, $org] = makeOrgUser('proposal-action-fails@example.com');
    $site = proposalSite($org);
    $service = app(ActionProposalService::class);
    $proposal = $service->propose($site, ActionType::RESOLVE_ALERT, ['alert_id' => 999999], 'x', $user->id);

    $approved = $service->approve($proposal, $site, $user->id);

    expect($approved->status)->toBe('failed')
        ->and($approved->result_json['error'])->toBe('alert_not_found');
});

it('rejects a proposal without ever executing it', function () {
    [$user, $org] = makeOrgUser('proposal-reject@example.com');
    $site = proposalSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $service = app(ActionProposalService::class);
    $proposal = $service->propose($site, ActionType::RESOLVE_ALERT, ['alert_id' => $alert->id], 'x', $user->id);

    $rejected = $service->reject($proposal, $user->id);

    expect($rejected->status)->toBe('rejected')
        ->and($rejected->executed_at)->toBeNull()
        ->and($alert->fresh()->is_resolved)->toBeFalse();
});

it('refuses to approve or reject a proposal that is not pending', function () {
    [$user, $org] = makeOrgUser('proposal-double-review@example.com');
    $site = proposalSite($org);
    $alert = Alert::withoutGlobalScope('organization')->create([
        'site_id' => $site->id, 'type' => 'revenue_drop', 'title' => 't', 'message' => 'm',
        'severity' => 'high', 'is_resolved' => false,
    ]);
    $service = app(ActionProposalService::class);
    $proposal = $service->propose($site, ActionType::RESOLVE_ALERT, ['alert_id' => $alert->id], 'x', $user->id);
    $service->approve($proposal, $site, $user->id);

    expect(fn () => $service->approve($proposal->fresh(), $site, $user->id))->toThrow(\RuntimeException::class);
    expect(fn () => $service->reject($proposal->fresh(), $user->id))->toThrow(\RuntimeException::class);
});

it('never sends a webhook proposal with a secret', function () {
    [$user, $org] = makeOrgUser('proposal-webhook-nosecret@example.com');
    $site = proposalSite($org);
    \Illuminate\Support\Facades\Http::fake();
    $service = app(ActionProposalService::class);
    $proposal = $service->propose($site, ActionType::SEND_WEBHOOK, ['url' => 'https://example.com/hook', 'payload' => []], 'x', $user->id);

    $service->approve($proposal, $site, $user->id);

    \Illuminate\Support\Facades\Http::assertSent(fn ($request) => ! $request->hasHeader('X-Insightistic-Signature'));
});
