"use client";

import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { ErrorState } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";

type Meter = { used: number; limit: number | null };
type Billing = {
  plan?: { name: string | null; effective_status?: string; billing_model?: string | null };
  lifetime_grant?: { active: boolean; plan_slug: string; name?: string; provider?: string } | null;
  stripe_subscription?: boolean;
  stripe_portal_available?: boolean;
  stripe_price?: { amount_minor: number; currency: string; billing_interval: string } | null;
  subscription?: {
    status: string;
    cancel_at_period_end: boolean;
    current_period_end: string | null;
    lifetime_grant?: Billing["lifetime_grant"];
  } | null;
  usage?: Record<string, Meter>;
  credits?: { monthly_included?: { granted: number; remaining: number }; total_usable?: number };
};

const METERS = {
  connected_sites: "Workspace websites",
  ai_insights: "Standard AI requests",
  jolly_messages: "JOLLY messages",
  reports: "Reports",
  crawl_pages: "Crawl pages",
};

function formatDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.valueOf()) ? null : date.toLocaleDateString(undefined, { dateStyle: "medium" });
}

export default function BillingPage() {
  const { data, loading, error, reload } = useApi<Billing>("/billing/subscription");
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  async function openPortal() {
    setBusy(true);
    setMessage(null);
    try {
      const result = await apiPost("/billing/portal");
      if (!result?.url) throw new Error("Stripe did not return a portal URL.");
      window.location.assign(result.url);
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Could not open the Stripe customer portal.");
      setBusy(false);
    }
  }

  if (error) return <><PageHeader title="Billing" /><ErrorState message={error} onRetry={reload} /></>;
  if (loading || !data) return <Spinner />;

  const grant = data.lifetime_grant ?? data.subscription?.lifetime_grant;
  const lifetime = grant?.active === true || data.plan?.billing_model === "lifetime";
  const stripe = !lifetime && data.stripe_subscription === true;
  const price = stripe ? data.stripe_price : null;
  const periodEnd = stripe ? formatDate(data.subscription?.current_period_end) : null;
  const status = stripe ? data.subscription?.status : data.plan?.effective_status;
  const monthCredits = data.credits?.monthly_included;

  return (
    <div>
      <PageHeader title="Billing" subtitle="Your current plan and billing details." />
      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Current plan">
          <div className="flex flex-wrap items-center gap-3">
            <span className="text-2xl font-bold text-fg">{grant?.name || data.plan?.name || "No active plan"}</span>
            {lifetime && <Badge tone="green" dot>Lifetime</Badge>}
            {stripe && <Badge tone="slate" dot>Stripe subscription</Badge>}
          </div>
          {lifetime && grant?.provider === "appsumo" && <p className="mt-3 text-sm text-muted">Redeemed through AppSumo. One-time purchase.</p>}
          {status && <p className="mt-3 text-sm capitalize text-muted">{status.replaceAll("_", " ")}</p>}
          {price && (
            <p className="mt-2 text-sm text-muted">
              {new Intl.NumberFormat(undefined, { style: "currency", currency: price.currency.toUpperCase() }).format(price.amount_minor / 100)}
              {price.billing_interval && <> / {price.billing_interval}</>}
            </p>
          )}
          {periodEnd && (
            <p className="mt-2 text-sm text-muted">
              {data.subscription?.cancel_at_period_end ? "Access through" : "Current period ends"} {periodEnd}
            </p>
          )}
          {stripe && data.stripe_portal_available && (
            <div className="mt-5">
              <Button variant="soft" onClick={openPortal} disabled={busy}>
                {busy ? "Opening Stripe…" : "Open Stripe customer portal"}
              </Button>
              {message && <p role="alert" className="mt-3 text-sm text-bad">{message}</p>}
            </div>
          )}
        </Card>

        <Card title="Plan usage">
          <dl className="divide-y divide-line">
            {Object.entries(METERS).map(([key, label]) => {
              const meter = data.usage?.[key];
              return meter && (
                <div key={key} className="flex items-center justify-between gap-3 py-2.5 text-sm">
                  <dt className="text-muted">{label}</dt>
                  <dd className="font-semibold text-fg">{num(meter.used)}{meter.limit != null && <> / {num(meter.limit)}</>}</dd>
                </div>
              );
            })}
            {monthCredits && (
              <div className="flex items-center justify-between gap-3 py-2.5 text-sm">
                <dt className="text-muted">Monthly Insight Credits remaining</dt>
                <dd className="font-semibold text-fg">{num(monthCredits.remaining)} / {num(monthCredits.granted)}</dd>
              </div>
            )}
          </dl>
        </Card>
      </div>
    </div>
  );
}
