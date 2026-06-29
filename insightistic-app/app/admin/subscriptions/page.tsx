"use client";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";

const TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", trialing: "amber", past_due: "amber", canceled: "slate", incomplete: "slate", unpaid: "red",
};

const fmtDate = (x?: string) => (x ? new Date(x).toLocaleDateString() : "—");

export default function AdminSubscriptions() {
  return (
    <AdminListPage
      title="Subscriptions"
      subtitle="Stripe subscription state across the platform. Secret keys are never exposed."
      endpoint="/admin/subscriptions"
      rowKey={(s: any) => s.id}
      searchPlaceholder="Search…"
      empty="No subscriptions yet."
      filters={[
        { key: "status", label: "Any status", options: ["active", "trialing", "past_due", "canceled", "incomplete"].map((v) => ({ value: v, label: v })) },
        { key: "canceled", label: "Cancellation", options: [{ value: "1", label: "Cancels at period end" }] },
      ]}
      columns={[
        { key: "organization", label: "Organization", primary: true, render: (s: any) => s.organization?.name || `Org #${s.organization_id}` },
        { key: "plan", label: "Plan", render: (s: any) => s.plan?.name || "—" },
        { key: "status", label: "Status", render: (s: any) => <Badge tone={TONE[s.status] || "slate"} dot>{s.status}</Badge> },
        { key: "stripe_customer_id", label: "Stripe customer", render: (s: any) => <span className="font-mono text-[11px] text-muted">{s.stripe_customer_id || "—"}</span> },
        { key: "current_period_end", label: "Renews", align: "right", render: (s: any) => fmtDate(s.current_period_end) },
        { key: "cancel_at_period_end", label: "Cancels", align: "right", render: (s: any) => s.cancel_at_period_end ? <Badge tone="red">yes</Badge> : "—" },
      ]}
    />
  );
}
