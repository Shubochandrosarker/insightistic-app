"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { ErrorState } from "@/components/dashboard/states";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";

const PLANS = { starter: "Starter", growth: "Growth", agency: "Agency", agency_pro: "Agency Pro" };

export default function SubscribePage() {
  const router = useRouter();
  const billing = useApi("/billing/subscription");
  const [selection, setSelection] = useState<{ plan: keyof typeof PLANS; kind: "monthly" | "yearly" } | null>();
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);

  useEffect(() => {
    const query = new URLSearchParams(window.location.search);
    const plan = query.get("plan");
    setSelection(plan && Object.hasOwn(PLANS, plan) ? {
      plan: plan as keyof typeof PLANS,
      kind: query.get("interval") === "yearly" ? "yearly" : "monthly",
    } : null);
  }, []);

  async function start(trial: boolean) {
    if (!selection) return;
    setBusy(true);
    setMessage(null);
    try {
      if (trial) {
        await apiPost("/billing/start-trial", { plan: selection.plan });
        router.replace("/dashboard/billing");
      } else {
        const response = await apiPost("/billing/stripe/checkout", selection);
        if (!response?.url) throw new Error("Stripe did not return a checkout URL.");
        window.location.assign(response.url);
      }
    } catch (error) {
      setMessage(error instanceof Error ? error.message : "Could not continue.");
      setBusy(false);
    }
  }

  if (billing.error) return <ErrorState message={billing.error} onRetry={billing.reload} />;
  if (billing.loading || selection === undefined) return <Spinner />;
  if (billing.data?.lifetime_grant?.active || billing.data?.stripe_subscription) {
    return <Card title="Your workspace already has an active plan"><Link href="/dashboard/billing" className="text-violet underline">View billing</Link></Card>;
  }
  if (!selection) {
    return <Card title="Choose a subscription"><Link href="/pricing" className="text-violet underline">See subscription plans</Link></Card>;
  }

  return (
    <div>
      <PageHeader title="Set up your subscription" />
      <Card title={PLANS[selection.plan]} subtitle={selection.kind === "yearly" ? "Annual billing" : "Monthly billing"}>
        <div className="flex flex-wrap gap-3">
          {billing.data?.trial?.eligible === true && <Button onClick={() => start(true)} disabled={busy}>Start free trial</Button>}
          <Button variant="soft" onClick={() => start(false)} disabled={busy}>Continue to Stripe checkout</Button>
        </div>
        {message && <p role="alert" className="mt-3 text-sm text-bad">{message}</p>}
      </Card>
    </div>
  );
}
