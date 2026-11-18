<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Plan;
use App\Models\License;
use App\Models\Organization;
use App\Models\Subscription;
use App\Services\AuditLogger;
use App\Services\LicenseService;
use App\Services\StripeService;
use App\Services\StripeWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Super Admin subscription operations (spec §13.8): inspect live Stripe
 * state, change plan with an explicit proration choice, cancel/reactivate,
 * extend trials, apply an internal entitlement override without falsifying
 * Stripe's own data, hand out a portal link, replay a webhook event, and
 * resync from Stripe. Every mutation is routed through AuditLogger.
 *
 * Every Stripe SDK call here is a genuine live network call — this
 * controller was written and syntax-checked in an environment with no
 * configured Stripe account, so none of the Stripe-calling paths (show's
 * live section, changePlan, cancel(immediate), extendTrial, portalLink,
 * replayWebhook, resync) have been exercised against a real account. They
 * follow the exact same StripeService/StripeWebhookProcessor patterns
 * BillingController already uses in production.
 */
class SubscriptionController extends AdminController
{
    public function __construct(
        private AuditLogger $audit,
        private LicenseService $licenses,
        private StripeService $stripe,
        private StripeWebhookProcessor $processor,
    ) {}

    /** Stripe ids are safe to show; secret keys live only in config and are never returned. */
    public function index(Request $request)
    {
        $query = Subscription::withoutGlobalScope('organization')
            ->with(['organization:id,name,slug', 'plan:id,name,slug,price_monthly']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($orgId = $request->query('organization_id')) {
            $query->where('organization_id', $orgId);
        }
        if ($request->filled('canceled')) {
            $query->where('cancel_at_period_end', $request->boolean('canceled'));
        }

        $this->applySort($query, $request, ['created_at', 'current_period_end', 'status'], 'created_at');

        return response()->json($query->paginate($this->perPage($request)));
    }

    /** POST /admin/subscriptions — create a provider-neutral manual entitlement. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'organization_id'          => ['required', 'integer', 'exists:organizations,id'],
            'plan_id'                  => ['required', 'integer', 'exists:plans,id'],
            'status'                   => ['required', 'in:active,trialing,past_due,canceled,incomplete,unpaid'],
            'billing_provider'         => ['nullable', 'string', 'max:40'],
            'provider_customer_id'     => ['nullable', 'string', 'max:190'],
            'provider_subscription_id' => ['nullable', 'string', 'max:190', 'unique:subscriptions,provider_subscription_id'],
            'provider_price_id'        => ['nullable', 'string', 'max:190'],
            'provider_status'          => ['nullable', 'string', 'max:40'],
            'current_period_start'     => ['nullable', 'date'],
            'current_period_end'       => ['nullable', 'date'],
            'trial_ends_at'            => ['required_if:status,trialing', 'nullable', 'date'],
            'cancel_at_period_end'     => ['sometimes', 'boolean'],
            'internal_override_active' => ['sometimes', 'boolean'],
            'internal_override_reason' => ['required_if:internal_override_active,true', 'nullable', 'string', 'max:500'],
        ]);

        $org = Organization::findOrFail($data['organization_id']);
        $plan = Plan::findOrFail($data['plan_id']);
        abort_unless(
            in_array($plan->slug, config('commercial.admin_plan_slugs', []), true),
            422,
            'That plan is no longer part of the current commercial catalog.'
        );

        $sub = Subscription::withoutGlobalScope('organization')->create([
            'organization_id' => $org->id,
            'plan_id' => $plan->id,
            'status' => $data['status'],
            'billing_provider' => $data['billing_provider'] ?? 'manual',
            'provider_customer_id' => $data['provider_customer_id'] ?? null,
            'provider_subscription_id' => $data['provider_subscription_id'] ?? null,
            'provider_price_id' => $data['provider_price_id'] ?? null,
            'provider_status' => $data['provider_status'] ?? $data['status'],
            'current_period_start' => $data['current_period_start'] ?? null,
            'current_period_end' => $data['current_period_end'] ?? null,
            'cancel_at_period_end' => (bool) ($data['cancel_at_period_end'] ?? false),
            'internal_override_active' => (bool) ($data['internal_override_active'] ?? false),
            'internal_override_reason' => (bool) ($data['internal_override_active'] ?? false) ? $data['internal_override_reason'] : null,
            'internal_override_by' => ($data['internal_override_active'] ?? false) ? $request->user()?->id : null,
            'internal_override_at' => ($data['internal_override_active'] ?? false) ? now() : null,
        ]);

        $org->update([
            'plan_id' => $plan->id,
            'trial_ends_at' => $data['trial_ends_at'] ?? null,
            'status' => match ($data['status']) {
                'trialing' => 'trialing',
                'past_due' => 'past_due',
                'canceled' => 'canceled',
                default => 'active',
            },
        ]);

        $rawLicenseKey = null;
        if (! License::withoutGlobalScope('organization')->where('organization_id', $org->id)->exists()) {
            $rawLicenseKey = $this->licenses->issue($org->fresh('plan'));
        }
        $this->licenses->syncForOrganization($org->fresh('plan'));
        $this->audit->log($request, 'subscription.create_manual', 'Subscription', $sub->id, null, ['organization_id' => $org->id, 'plan_id' => $plan->id, 'status' => $sub->status]);

        return response()->json(['subscription' => $sub->fresh(['organization', 'plan']), 'license_key' => $rawLicenseKey], 201);
    }

    /**
     * GET /admin/subscriptions/{id} — local row + (when a live Stripe
     * subscription is attached) customer, invoices, and payment state
     * fetched straight from Stripe. The `stripe` key is omitted, not null,
     * if the call fails (misconfigured account, deleted object, network) —
     * check for its presence rather than assuming it's always there.
     */
    public function show(int $id)
    {
        $sub = $this->find($id);
        $payload = [
            'subscription' => $sub,
            'plans'        => Plan::whereIn('slug', config('commercial.admin_plan_slugs', []))
                ->orderBy('sort_order')
                ->orderBy('price_monthly')
                ->get(['id', 'name', 'slug', 'price_monthly', 'price_yearly']),
        ];

        if ($sub->stripe_subscription_id) {
            try {
                $live = $this->stripe->getSubscription($sub->stripe_subscription_id);
                $payload['stripe'] = [
                    'subscription' => $live,
                    'customer'     => $sub->stripe_customer_id ? $this->stripe->getCustomer($sub->stripe_customer_id) : null,
                    'invoices'     => $sub->stripe_customer_id ? $this->stripe->listInvoices($sub->stripe_customer_id, 10)->data : [],
                ];
            } catch (\Throwable $e) {
                $payload['stripe_error'] = $e->getMessage();
            }
        }

        return response()->json($payload);
    }

    /** POST /admin/subscriptions/{id}/change-plan — moves the live Stripe subscription, with an explicit proration choice. */
    public function changePlan(Request $request, int $id)
    {
        $data = $request->validate([
            'plan_id'            => ['required', 'integer', 'exists:plans,id'],
            'interval'           => ['required', 'in:monthly,yearly'],
            'proration_behavior' => ['required', 'in:create_prorations,none,always_invoice'],
            'reason'             => ['nullable', 'string', 'max:500'],
        ]);

        $sub = $this->find($id);
        $plan = Plan::findOrFail($data['plan_id']);
        abort_unless(
            in_array($plan->slug, config('commercial.recurring_plan_slugs', []), true)
                && $plan->is_active,
            422,
            'Only active recurring Stripe plans can be assigned to a live subscription.'
        );
        $priceId = $data['interval'] === 'yearly' ? $plan->stripe_price_id_yearly : $plan->stripe_price_id_monthly;

        if (! $sub->stripe_subscription_id) {
            return response()->json(['message' => 'This organization has no live Stripe subscription to move.', 'code' => 'no_stripe_subscription'], 422);
        }
        if (! $priceId) {
            return response()->json(['message' => 'That plan/interval has no Stripe price configured.', 'code' => 'price_not_configured'], 422);
        }

        $before = $sub->only(['plan_id', 'status']);

        $this->stripe->changePlan($sub->stripe_subscription_id, $priceId, $data['proration_behavior']);

        $sub->plan_id = $plan->id;
        $sub->save();
        $sub->organization->update(['plan_id' => $plan->id]);
        $this->licenses->syncForOrganization($sub->organization->fresh());

        $this->audit->log(
            $request, 'subscription.change_plan', 'Subscription', $sub->id,
            $before, ['plan_id' => $plan->id, 'proration_behavior' => $data['proration_behavior']],
            $data['reason'] ?? null,
        );

        return response()->json(['subscription' => $sub->fresh()]);
    }

    /** POST /admin/subscriptions/{id}/cancel — at period end (default) or immediately. */
    public function cancel(Request $request, int $id)
    {
        $data = $request->validate([
            'immediate' => ['sometimes', 'boolean'],
            'reason'    => ['nullable', 'string', 'max:500'],
        ]);
        $immediate = (bool) ($data['immediate'] ?? false);

        $sub = $this->find($id);
        if (! $sub->stripe_subscription_id) {
            return response()->json(['message' => 'No live Stripe subscription to cancel.', 'code' => 'no_stripe_subscription'], 422);
        }

        $before = $sub->only(['status', 'cancel_at_period_end']);

        if ($immediate) {
            $this->stripe->cancelImmediately($sub->stripe_subscription_id);
            $sub->update(['status' => 'canceled', 'cancel_at_period_end' => false]);
            $sub->organization->update(['status' => 'canceled']);
        } else {
            $this->stripe->setCancelAtPeriodEnd($sub->stripe_subscription_id, true);
            $sub->update(['cancel_at_period_end' => true]);
        }
        $this->licenses->syncForOrganization($sub->organization->fresh());

        $this->audit->log(
            $request, 'subscription.cancel', 'Subscription', $sub->id,
            $before, $sub->fresh()->only(['status', 'cancel_at_period_end']),
            ($data['reason'] ?? null) . ($immediate ? ' [immediate]' : ' [at period end]'),
        );

        return response()->json(['subscription' => $sub->fresh()]);
    }

    /** POST /admin/subscriptions/{id}/reactivate — undo a pending cancel-at-period-end. */
    public function reactivate(Request $request, int $id)
    {
        $sub = $this->find($id);
        if (! $sub->stripe_subscription_id || ! $sub->cancel_at_period_end) {
            return response()->json(['message' => 'Nothing to reactivate.', 'code' => 'no_pending_cancel'], 422);
        }

        $before = $sub->only(['cancel_at_period_end']);
        $this->stripe->setCancelAtPeriodEnd($sub->stripe_subscription_id, false);
        $sub->update(['cancel_at_period_end' => false]);
        $this->licenses->syncForOrganization($sub->organization->fresh());

        $this->audit->log($request, 'subscription.reactivate', 'Subscription', $sub->id, $before, ['cancel_at_period_end' => false]);

        return response()->json(['subscription' => $sub->fresh()]);
    }

    /** POST /admin/subscriptions/{id}/extend-trial — pushes trial_ends_at (and the live Stripe trial, if trialing). */
    public function extendTrial(Request $request, int $id)
    {
        $data = $request->validate([
            'days'   => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $sub = $this->find($id);
        $org = $sub->organization;
        $before = $org->only(['trial_ends_at', 'status']);

        $newEnd = ($org->trial_ends_at && $org->trial_ends_at->isFuture() ? $org->trial_ends_at : Carbon::now())
            ->copy()->addDays($data['days']);

        $org->trial_ends_at = $newEnd;
        if ($org->status !== 'trialing') {
            $org->status = 'trialing';
        }
        $org->save();

        if ($sub->stripe_subscription_id && $sub->status === 'trialing') {
            try {
                $this->stripe->extendTrial($sub->stripe_subscription_id, $newEnd);
            } catch (\Throwable $e) {
                // Local trial extension already applied; Stripe couldn't be reached/updated.
                // Surface it rather than silently pretending both sides are in sync.
                return response()->json([
                    'organization' => $org->fresh(),
                    'warning'      => 'Trial extended locally, but the live Stripe subscription could not be updated: ' . $e->getMessage(),
                ]);
            }
        }

        $this->licenses->syncForOrganization($org->fresh());
        $this->audit->log($request, 'subscription.extend_trial', 'Subscription', $sub->id, $before, $org->fresh()->only(['trial_ends_at', 'status']), $data['reason'] ?? null);

        return response()->json(['organization' => $org->fresh()]);
    }

    /**
     * POST /admin/subscriptions/{id}/override — force entitlement to 'active'
     * without touching Stripe (comps, disputes-in-progress, manual deals).
     * `active: false` clears an existing override.
     */
    public function override(Request $request, int $id)
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required_if:active,true', 'nullable', 'string', 'max:500'],
        ]);

        $sub = $this->find($id);
        $before = $sub->only(['internal_override_active', 'internal_override_reason']);

        $sub->internal_override_active = $data['active'];
        $sub->internal_override_reason = $data['active'] ? $data['reason'] : null;
        $sub->internal_override_by = $data['active'] ? $request->user()?->id : null;
        $sub->internal_override_at = $data['active'] ? now() : null;
        $sub->save();

        $this->licenses->syncForOrganization($sub->organization->fresh());

        $this->audit->log(
            $request, 'subscription.override', 'Subscription', $sub->id,
            $before, $sub->fresh()->only(['internal_override_active', 'internal_override_reason']),
            $data['reason'] ?? null,
        );

        return response()->json(['subscription' => $sub->fresh()]);
    }

    /** GET /admin/subscriptions/{id}/portal-link — a support-facing Stripe portal URL for this customer. */
    public function portalLink(int $id)
    {
        $sub = $this->find($id);
        if (! $sub->stripe_customer_id) {
            return response()->json(['message' => 'No Stripe customer on this subscription yet.'], 422);
        }

        $url = $this->stripe->portalUrl($sub->stripe_customer_id, rtrim(config('insightistic.app_url'), '/') . '/billing');

        return response()->json(['url' => $url]);
    }

    /**
     * POST /admin/subscriptions/{id}/replay-webhook — re-run a specific
     * Stripe event through the exact same processor the live webhook
     * endpoint uses. Safe because every processor method converges to the
     * same state no matter how many times it's applied to the same input.
     */
    public function replayWebhook(Request $request, int $id)
    {
        $data = $request->validate([
            'event_id' => ['required', 'string', 'starts_with:evt_'],
            'reason'   => ['nullable', 'string', 'max:500'],
        ]);

        $sub = $this->find($id);

        try {
            $event = $this->stripe->retrieveEvent($data['event_id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not fetch that event from Stripe: ' . $e->getMessage()], 422);
        }

        $before = $sub->only(['status', 'plan_id', 'cancel_at_period_end']);
        $this->processor->process($event);

        $this->audit->log(
            $request, 'subscription.replay_webhook', 'Subscription', $sub->id,
            $before, $sub->fresh()->only(['status', 'plan_id', 'cancel_at_period_end']),
            trim(($data['reason'] ?? '') . ' [event ' . $data['event_id'] . ', type ' . $event->type . ']'),
        );

        return response()->json(['subscription' => $sub->fresh(), 'event_type' => $event->type]);
    }

    /**
     * POST /admin/subscriptions/{id}/resync — pull the subscription straight
     * from Stripe (not from a stored event) and reproject local state from it.
     */
    public function resync(Request $request, int $id)
    {
        $sub = $this->find($id);
        if (! $sub->stripe_subscription_id) {
            return response()->json(['message' => 'No live Stripe subscription to resync from.', 'code' => 'no_stripe_subscription'], 422);
        }

        try {
            $live = $this->stripe->getSubscription($sub->stripe_subscription_id);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Could not fetch the live subscription from Stripe: ' . $e->getMessage()], 422);
        }

        $before = $sub->only(['status', 'plan_id', 'current_period_end', 'cancel_at_period_end']);
        $this->processor->resyncFromLive($sub->organization, $live);

        $this->audit->log($request, 'subscription.resync', 'Subscription', $sub->id, $before, $sub->fresh()->only(['status', 'plan_id', 'current_period_end', 'cancel_at_period_end']));

        return response()->json(['subscription' => $sub->fresh()]);
    }

    private function find(int $id): Subscription
    {
        return Subscription::withoutGlobalScope('organization')->with('organization')->findOrFail($id);
    }
}
