"use client";
import { useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { money0, timeAgo } from "@/lib/format";
import { ArrowLeft } from "lucide-react";

const TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", trialing: "amber", past_due: "amber", canceled: "slate", incomplete: "slate", unpaid: "red",
};

const fmtDate = (x?: string | null) => (x ? new Date(x).toLocaleDateString() : "—");
const fmtMoney = (cents?: number, currency = "usd") => (cents == null ? "—" : `${money0(cents / 100)} ${currency.toUpperCase()}`);

export default function AdminSubscriptionDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, loading, reload } = useApi<any>(`/admin/subscriptions/${id}`);
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  async function run(action: string, body?: any) {
    setBusy(true);
    setNotice(null);
    try {
      const res = await apiPost(`/admin/subscriptions/${id}/${action}`, body);
      if (res?.warning) setNotice(res.warning);
      reload();
    } catch (e: any) {
      window.alert(e.message || "Action failed.");
    } finally {
      setBusy(false);
    }
  }

  if (loading || !data) return <Spinner />;

  const sub = data.subscription;
  const stripe = data.stripe;
  const org = sub.organization;

  return (
    <div>
      <Link href="/admin/subscriptions" className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg">
        <ArrowLeft size={15} /> Subscriptions
      </Link>
      <PageHeader
        title={org?.name || `Org #${sub.organization_id}`}
        subtitle={`Subscription #${sub.id} · ${sub.plan?.name || "no plan"}`}
        right={<Badge tone={TONE[sub.status] || "slate"} dot>{sub.status}</Badge>}
      />

      {notice && (
        <div className="mb-4 rounded-xl border border-warn/30 bg-warn/10 px-4 py-3 text-sm text-warn">{notice}</div>
      )}

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card title="Local state">
            <dl className="space-y-2.5 text-sm">
              <div className="flex justify-between"><dt className="text-muted">Stripe customer</dt><dd className="font-mono text-[11px] text-fg">{sub.stripe_customer_id || "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Stripe subscription</dt><dd className="font-mono text-[11px] text-fg">{sub.stripe_subscription_id || "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Current period end</dt><dd className="text-fg">{fmtDate(sub.current_period_end)}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Cancels at period end</dt><dd className="text-fg">{sub.cancel_at_period_end ? "Yes" : "No"}</dd></div>
              {sub.internal_override_active && (
                <div className="flex justify-between"><dt className="text-muted">Internal override</dt><dd><Badge tone="violet">forced active — {sub.internal_override_reason}</Badge></dd></div>
              )}
            </dl>
          </Card>

          {stripe && (
            <Card title="Live Stripe state">
              <dl className="space-y-2.5 text-sm">
                <div className="flex justify-between"><dt className="text-muted">Stripe status</dt><dd className="text-fg">{stripe.subscription?.status}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">Customer email</dt><dd className="text-fg">{stripe.customer?.email || "—"}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">Default payment method</dt><dd className="text-fg">{stripe.customer?.invoice_settings?.default_payment_method?.card ? `${stripe.customer.invoice_settings.default_payment_method.card.brand} •••• ${stripe.customer.invoice_settings.default_payment_method.card.last4}` : "—"}</dd></div>
              </dl>
              {stripe.invoices?.length > 0 && (
                <div className="mt-4 border-t border-line pt-3">
                  <span className="mb-2 block text-xs font-semibold text-fg">Recent invoices</span>
                  <div className="space-y-1.5">
                    {stripe.invoices.map((inv: any) => (
                      <div key={inv.id} className="flex items-center justify-between text-sm">
                        <span className="text-fg">{fmtMoney(inv.amount_paid ?? inv.amount_due, inv.currency)}</span>
                        <Badge tone={inv.status === "paid" ? "green" : inv.status === "open" ? "amber" : "slate"}>{inv.status}</Badge>
                        <span className="text-[11px] text-muted">{inv.created ? timeAgo(new Date(inv.created * 1000).toISOString()) : "—"}</span>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </Card>
          )}
          {data.stripe_error && (
            <Card title="Live Stripe state">
              <p className="text-sm text-bad">Could not reach Stripe: {data.stripe_error}</p>
            </Card>
          )}

          <Card title="Change plan">
            <ChangePlanForm plans={data.plans || []} currentPlanId={sub.plan_id} busy={busy} onSubmit={(body) => run("change-plan", body)} />
          </Card>

          <Card title="Cancel / reactivate">
            <div className="flex flex-wrap gap-3">
              {sub.cancel_at_period_end ? (
                <Button onClick={() => run("reactivate")} disabled={busy}>Reactivate</Button>
              ) : (
                <>
                  <Button variant="ghost" onClick={() => run("cancel", { immediate: false, reason: window.prompt("Reason (optional)?") || undefined })} disabled={busy}>
                    Cancel at period end
                  </Button>
                  <Button
                    variant="danger"
                    onClick={() => {
                      if (!window.confirm("Cancel immediately? This ends access right now, not at period end.")) return;
                      run("cancel", { immediate: true, reason: window.prompt("Reason (optional)?") || undefined });
                    }}
                    disabled={busy}
                  >
                    Cancel immediately
                  </Button>
                </>
              )}
            </div>
          </Card>

          <Card title="Extend trial">
            <ExtendTrialForm busy={busy} onSubmit={(days, reason) => run("extend-trial", { days, reason })} />
          </Card>

          <Card title="Internal override">
            <p className="mb-3 text-xs text-muted">Forces entitlement to active without changing anything in Stripe. Use for comps, disputes-in-progress, or manual deals.</p>
            {sub.internal_override_active ? (
              <Button variant="ghost" onClick={() => run("override", { active: false })} disabled={busy}>Clear override</Button>
            ) : (
              <OverrideForm busy={busy} onSubmit={(reason) => run("override", { active: true, reason })} />
            )}
          </Card>

          <Card title="Replay a Stripe webhook event">
            <p className="mb-3 text-xs text-muted">Re-runs a specific Stripe event id through the same processor the live webhook uses. Safe to repeat.</p>
            <ReplayForm busy={busy} onSubmit={(eventId, reason) => run("replay-webhook", { event_id: eventId, reason })} />
          </Card>
        </div>

        <div className="space-y-5">
          <Card title="Organization">
            <dl className="space-y-2.5 text-sm">
              <div className="flex justify-between"><dt className="text-muted">Name</dt><dd className="text-right font-medium text-fg"><Link href={`/admin/organizations/${org?.id}`} className="hover:underline">{org?.name}</Link></dd></div>
              <div className="flex justify-between"><dt className="text-muted">Slug</dt><dd className="text-right text-fg">{org?.slug}</dd></div>
            </dl>
          </Card>

          <Card title="Support tools">
            <div className="space-y-2">
              <Button
                variant="ghost"
                className="w-full"
                disabled={busy || !sub.stripe_customer_id}
                onClick={async () => {
                  setBusy(true);
                  try {
                    const res = await apiGet(`/admin/subscriptions/${id}/portal-link`);
                    window.open(res.url, "_blank");
                  } catch (e: any) {
                    window.alert(e.message || "Could not get a portal link.");
                  } finally {
                    setBusy(false);
                  }
                }}
              >
                Open Stripe portal
              </Button>
              <Button variant="ghost" className="w-full" disabled={busy || !sub.stripe_subscription_id} onClick={() => run("resync")}>
                Resync from Stripe
              </Button>
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}

function ChangePlanForm({ plans, currentPlanId, busy, onSubmit }: { plans: any[]; currentPlanId: number; busy: boolean; onSubmit: (body: any) => void }) {
  const [planId, setPlanId] = useState(currentPlanId);
  const [interval, setInterval_] = useState<"monthly" | "yearly">("monthly");
  const [proration, setProration] = useState<"create_prorations" | "none" | "always_invoice">("create_prorations");

  return (
    <div className="flex flex-wrap items-end gap-3">
      <label className="block">
        <span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span>
        <select value={planId} onChange={(e) => setPlanId(Number(e.target.value))} className="rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
          {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
        </select>
      </label>
      <label className="block">
        <span className="mb-1.5 block text-xs font-semibold text-fg">Interval</span>
        <select value={interval} onChange={(e) => setInterval_(e.target.value as any)} className="rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
          <option value="monthly">Monthly</option>
          <option value="yearly">Yearly</option>
        </select>
      </label>
      <label className="block">
        <span className="mb-1.5 block text-xs font-semibold text-fg">Proration</span>
        <select value={proration} onChange={(e) => setProration(e.target.value as any)} className="rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand">
          <option value="create_prorations">Prorate now</option>
          <option value="always_invoice">Prorate + invoice immediately</option>
          <option value="none">No proration</option>
        </select>
      </label>
      <Button
        disabled={busy}
        onClick={() => onSubmit({ plan_id: planId, interval, proration_behavior: proration, reason: window.prompt("Reason (optional)?") || undefined })}
      >
        Change plan
      </Button>
    </div>
  );
}

function ExtendTrialForm({ busy, onSubmit }: { busy: boolean; onSubmit: (days: number, reason?: string) => void }) {
  const [days, setDays] = useState(14);
  return (
    <div className="flex flex-wrap items-end gap-3">
      <Field label="Extend by (days)" type="number" min={1} max={365} value={days} onChange={(e) => setDays(Number(e.target.value))} />
      <Button disabled={busy} onClick={() => onSubmit(days, window.prompt("Reason (optional)?") || undefined)}>Extend trial</Button>
    </div>
  );
}

function OverrideForm({ busy, onSubmit }: { busy: boolean; onSubmit: (reason: string) => void }) {
  const [reason, setReason] = useState("");
  return (
    <div className="flex flex-wrap items-end gap-3">
      <Field label="Reason (required)" value={reason} onChange={(e) => setReason(e.target.value)} />
      <Button disabled={busy || !reason.trim()} onClick={() => onSubmit(reason.trim())}>Force active</Button>
    </div>
  );
}

function ReplayForm({ busy, onSubmit }: { busy: boolean; onSubmit: (eventId: string, reason?: string) => void }) {
  const [eventId, setEventId] = useState("");
  return (
    <div className="flex flex-wrap items-end gap-3">
      <Field label="Stripe event id" placeholder="evt_..." value={eventId} onChange={(e) => setEventId(e.target.value)} />
      <Button disabled={busy || !eventId.trim()} onClick={() => onSubmit(eventId.trim(), window.prompt("Reason (optional)?") || undefined)}>Replay</Button>
    </div>
  );
}
