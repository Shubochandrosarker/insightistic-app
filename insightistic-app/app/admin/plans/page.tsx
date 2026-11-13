"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPatch, apiPost, apiGet } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { money0, num } from "@/lib/format";
import { ChevronDown, ChevronUp, Users } from "lucide-react";

// Mirrors LicenseService::FEATURES (insightistic-api) — no endpoint exposes
// this vocabulary today, so it's kept in sync by hand, same as this app's
// other small hardcoded enums (e.g. the license event-type labels).
const FEATURES = [
  "basic_analytics", "traffic_analytics", "seo_analytics", "performance_tracking",
  "woocommerce_analytics", "engagement_analytics", "ai_insights", "ai_copilot",
  "advanced_reports", "scheduled_reports", "alerts", "data_export",
  "multi_site", "team_access", "agency_white_label", "custom_domain", "api_access",
  "white_label_reports", "white_label_dashboard", "priority_support",
  "ga4_analytics", "search_console_analytics", "cloudflare_analytics",
  "pagespeed_analytics", "rank_tracking", "local_seo_analytics",
  "competitor_insights", "market_insights", "custom_knowledge_base",
  "custom_workflow_builder",
];

function centsToDollars(c: number) { return (c / 100).toFixed(2); }
function dollarsToCents(d: string) { return Math.round(parseFloat(d || "0") * 100); }

function PlanEditor({ plan, onSaved }: { plan: any; onSaved: () => void }) {
  const [form, setForm] = useState({
    name: plan.name,
    price_monthly: centsToDollars(plan.price_monthly),
    price_yearly: centsToDollars(plan.price_yearly),
    site_limit: plan.site_limit,
    user_limit: plan.user_limit,
    ai_insight_limit: plan.ai_insight_limit,
    report_limit: plan.report_limit,
    sort_order: plan.sort_order ?? 0,
    is_active: plan.is_active,
    is_featured: plan.is_featured,
    features_json: (plan.features_json || []) as string[],
  });
  const [busy, setBusy] = useState(false);
  const [impact, setImpact] = useState<{ subscriber_count: number } | null>(null);

  function toggleFeature(f: string) {
    setForm((s) => ({
      ...s,
      features_json: s.features_json.includes(f) ? s.features_json.filter((x) => x !== f) : [...s.features_json, f],
    }));
  }

  async function checkImpact() {
    const res = await apiGet(`/admin/plans/${plan.id}/impact`);
    setImpact(res);
  }

  async function save() {
    setBusy(true);
    try {
      await apiPatch(`/admin/plans/${plan.id}`, {
        ...form,
        price_monthly: dollarsToCents(form.price_monthly),
        price_yearly: dollarsToCents(form.price_yearly),
      });
      onSaved();
    } catch (e: any) {
      window.alert(e.message || "Failed to save.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="space-y-4 border-t border-line pt-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Field label="Name" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
        <Field label="Monthly price ($)" type="number" step="0.01" value={form.price_monthly} onChange={(e) => setForm({ ...form, price_monthly: e.target.value })} />
        <Field label="Yearly price ($)" type="number" step="0.01" value={form.price_yearly} onChange={(e) => setForm({ ...form, price_yearly: e.target.value })} />
        <Field label="Sort order" type="number" value={form.sort_order} onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) })} />
        <Field label="Site limit" type="number" value={form.site_limit} onChange={(e) => setForm({ ...form, site_limit: Number(e.target.value) })} />
        <Field label="User limit" type="number" value={form.user_limit} onChange={(e) => setForm({ ...form, user_limit: Number(e.target.value) })} />
        <Field label="AI insight limit / mo" type="number" value={form.ai_insight_limit} onChange={(e) => setForm({ ...form, ai_insight_limit: Number(e.target.value) })} />
        <Field label="Report limit / mo" type="number" value={form.report_limit} onChange={(e) => setForm({ ...form, report_limit: Number(e.target.value) })} />
      </div>

      <div className="flex flex-wrap gap-4">
        <label className="flex items-center gap-2 text-sm text-fg">
          <input type="checkbox" checked={form.is_active} onChange={(e) => setForm({ ...form, is_active: e.target.checked })} />
          Visible on pricing page
        </label>
        <label className="flex items-center gap-2 text-sm text-fg">
          <input type="checkbox" checked={form.is_featured} onChange={(e) => setForm({ ...form, is_featured: e.target.checked })} />
          Featured ("Best value")
        </label>
      </div>

      <div>
        <span className="mb-2 block text-xs font-semibold text-fg">Entitlements</span>
        <div className="flex flex-wrap gap-2">
          {FEATURES.map((f) => (
            <button
              key={f}
              onClick={() => toggleFeature(f)}
              className={`rounded-full px-3 py-1.5 text-xs font-semibold capitalize transition ${
                form.features_json.includes(f) ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
              }`}
            >
              {f.replace(/_/g, " ")}
            </button>
          ))}
        </div>
      </div>

      <div className="flex flex-wrap items-center gap-3 border-t border-line pt-3">
        <Button variant="ghost" onClick={checkImpact}>
          <Users size={15} /> Check impact
        </Button>
        {impact && (
          <span className="text-xs text-muted">
            {impact.subscriber_count} organization{impact.subscriber_count === 1 ? "" : "s"} currently on this plan.
          </span>
        )}
        <Button onClick={save} disabled={busy} className="ml-auto">{busy ? "Saving…" : "Save changes"}</Button>
      </div>
    </div>
  );
}

export default function AdminPlans() {
  const { data, loading, reload } = useApi<{ plans: any[] }>("/admin/plans");
  const [expanded, setExpanded] = useState<number | null>(null);
  const [creating, setCreating] = useState(false);

  async function createPlan() {
    const name = window.prompt("New plan name?");
    if (!name) return;
    const slug = name.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/(^-|-$)/g, "");
    setCreating(true);
    try {
      await apiPost("/admin/plans", {
        name, slug, price_monthly: 0, price_yearly: 0,
        site_limit: 1, user_limit: 1, ai_insight_limit: 10, report_limit: 4,
      });
      reload();
    } catch (e: any) {
      window.alert(e.message || "Failed to create plan.");
    } finally {
      setCreating(false);
    }
  }

  if (loading) return <Spinner />;

  const plans = data?.plans || [];

  return (
    <div>
      <PageHeader
        title="Plans"
        subtitle="The single source of truth for pricing, limits, and entitlements."
        right={<Button onClick={createPlan} disabled={creating}>{creating ? "Creating…" : "New plan"}</Button>}
      />

      <div className="space-y-3">
        {plans.map((p) => (
          <Card key={p.id}>
            <button onClick={() => setExpanded(expanded === p.id ? null : p.id)} className="flex w-full items-center justify-between gap-3 text-left">
              <div className="flex items-center gap-3">
                <span className="font-semibold text-fg">{p.name}</span>
                <Badge tone={p.is_active ? "green" : "slate"} dot>{p.is_active ? "Visible" : "Hidden"}</Badge>
                {p.is_featured && <Badge tone="violet">Featured</Badge>}
                <span className="text-xs text-muted">{money0(p.price_monthly / 100)}/mo · {num(p.organizations_count)} orgs</span>
              </div>
              {expanded === p.id ? <ChevronUp size={16} className="text-muted" /> : <ChevronDown size={16} className="text-muted" />}
            </button>
            {expanded === p.id && <PlanEditor plan={p} onSaved={() => { reload(); }} />}
          </Card>
        ))}
      </div>
    </div>
  );
}
