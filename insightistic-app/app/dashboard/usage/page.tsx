"use client";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { num } from "@/lib/format";

/**
 * Usage dashboard (Phase 15): renders whatever GET /billing/subscription
 * reports in `usage` — the payload is the only source of numbers. The
 * controller exposes ai_insights and reports today; anything else known to
 * plans but not yet metered shows as a coming-soon row, never an invented
 * count. Limits of null mean unmetered (∞) on the server side.
 */

type UsageEntry = { used?: number | null; limit?: number | null };
type CreditSummary = {
  monthly_included?: { granted?: number; remaining?: number };
  purchased?: { remaining?: number };
  total_usable?: number;
  next_reset_at?: string;
};

/** Friendly names for the dimensions the API currently meters. */
const USAGE_LABELS: Record<string, string> = {
  tracked_keywords: "Tracked keywords",
  crawl_pages: "Crawl pages",
  ai_prompt_runs: "AI prompt runs",
  ai_insights: "Standard AI requests",
  jolly_messages: "JOLLY messages",
  connected_sites: "Workspace websites",
  reports: "Reports",
};

function usageLabel(key: string): string {
  return (
    USAGE_LABELS[key] ??
    key
      .split("_")
      .filter(Boolean)
      .map((w) => w[0]?.toUpperCase() + w.slice(1))
      .join(" ")
  );
}

/** Tier number from a grant's plan slug ("founder_tier_2" -> 2). */
function grantTierLabel(planSlug: string): string {
  const appSumoTier = planSlug.match(/^appsumo_tier_(\d+)$/);
  if (appSumoTier) return "AppSumo Tier " + appSumoTier[1];
  const m = planSlug.match(/founder_tier_(\d+)/);
  return m ? "Founder Tier " + m[1] : planSlug.replace(/^angel_/, "Angel ").replace(/_/g, " ");
}

function UsageBar({ label, used, limit }: { label: string; used: number; limit: number | null }) {
  const ratio = limit ? Math.min(100, Math.round((used / limit) * 100)) : 0;
  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between text-sm">
        <span className="text-muted">{label}</span>
        <span className="font-semibold text-fg">{num(used)} / {limit == null ? "∞" : num(limit)}</span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
        <div className="h-full rounded-full bg-gradient-to-r from-brand2 to-brand" style={{ width: `${ratio}%` }} />
      </div>
    </div>
  );
}

/** The lifetime grant rides on the subscription payload (or alongside it). */
function lifetimeGrantOf(d: any): { active?: boolean; plan_slug?: string } | null {
  const g = d?.lifetime_grant ?? d?.subscription?.lifetime_grant;
  return g?.active && g?.plan_slug ? g : null;
}

export default function UsagePage() {
  const { data, loading, error, reload } = useApi<any>("/billing/subscription");

  if (loading) {
    return (
      <div>
        <PageHeader title="Usage" subtitle="Your usage this billing month against your plan's limits." />
        <Spinner />
      </div>
    );
  }

  if (error) {
    return (
      <div>
        <PageHeader title="Usage" subtitle="Your usage this billing month against your plan's limits." />
        <ErrorState message={error} onRetry={reload} />
      </div>
    );
  }

  const usage: Record<string, UsageEntry> =
    data?.usage && typeof data.usage === "object" && !Array.isArray(data.usage) ? data.usage : {};
  const usageKeys = Object.keys(usage);
  const grant = lifetimeGrantOf(data);
  const credits: CreditSummary | null = data?.credits && typeof data.credits === "object" ? data.credits : null;

  return (
    <div>
      <PageHeader title="Usage" subtitle="Your usage this billing month against your plan's limits." />

      {credits && (
        <Card title="AI credits" subtitle="Your live Insightistic Cloud AI balance across connected sites and dashboard features." className="mb-5">
          <div className="grid gap-4 sm:grid-cols-3">
            <div className="rounded-xl border border-line bg-card2 p-4">
              <p className="text-xs uppercase tracking-wide text-muted">Available</p>
              <p className="mt-1 text-2xl font-bold text-fg">{num(credits.total_usable ?? 0)}</p>
              <p className="mt-1 text-xs text-muted">credits remaining</p>
            </div>
            <div className="rounded-xl border border-line bg-card2 p-4">
              <p className="text-xs uppercase tracking-wide text-muted">Monthly</p>
              <p className="mt-1 text-lg font-bold text-fg">{num(credits.monthly_included?.remaining ?? 0)}</p>
              <p className="mt-1 text-xs text-muted">of {num(credits.monthly_included?.granted ?? 0)} included</p>
            </div>
            <div className="rounded-xl border border-line bg-card2 p-4">
              <p className="text-xs uppercase tracking-wide text-muted">Purchased</p>
              <p className="mt-1 text-lg font-bold text-fg">{num(credits.purchased?.remaining ?? 0)}</p>
              <p className="mt-1 text-xs text-muted">non-expiring credits</p>
            </div>
          </div>
          {credits.next_reset_at && (
            <p className="mt-3 text-xs text-muted">Monthly credits reset {new Date(credits.next_reset_at).toLocaleDateString()}.</p>
          )}
        </Card>
      )}

      {usageKeys.length === 0 ? (
        <EmptyState
          title="No usage reported yet"
          hint="Once your workspace starts generating AI insights and reports, this month's consumption shows up here."
        />
      ) : (
        <Card title="This billing month">
          <div className="space-y-4">
            {usageKeys.map((k) => (
              <UsageBar
                key={k}
                label={usageLabel(k)}
                used={usage[k]?.used ?? 0}
                limit={usage[k]?.limit ?? null}
              />
            ))}
          </div>
        </Card>
      )}

      {grant && (
        <Card title="Lifetime access" className="mt-5">
          <div className="flex items-center gap-3">
            <Badge tone="green" dot>Active</Badge>
            <span className="text-sm font-semibold text-fg">{grantTierLabel(grant.plan_slug!)} lifetime</span>
          </div>
          <p className="mt-2 text-sm text-muted">
            Your lifetime plan includes its published features and monthly allowances for as long as Insightistic operates.
          </p>
        </Card>
      )}

    </div>
  );
}
