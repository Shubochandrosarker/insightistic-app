"use client";
import { useState } from "react";
import Link from "next/link";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { AreaTrend, LineTrend } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { OnboardingChecklist } from "@/components/dashboard/OnboardingChecklist";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { ErrorState, StaleBadge, SourceFreshness } from "@/components/dashboard/states";
import { money0, money, num, timeAgo } from "@/lib/format";
import { Sparkles, Package, AlertTriangle, Lightbulb } from "lucide-react";

const PERIOD_LABEL: Record<string, string> = {
  today: "Today", yesterday: "Yesterday", last_7_days: "Last 7 days",
  last_30_days: "Last 30 days", this_month: "This month", last_month: "Last month",
};

const CWV_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  good: "green",
  needs_improvement: "amber",
  poor: "red",
};

const SEV_TONE: Record<string, "red" | "amber" | "slate"> = {
  high: "red", medium: "amber", low: "slate",
};

/** KPI tile + the small source/period caption line under it. */
function Tile({
  label, value, delta, series, color, source, periodLabel, hint,
}: {
  label: string;
  value: string | number;
  delta?: number | null;
  series?: { v: number }[];
  color?: string;
  source?: string;
  periodLabel?: string;
  hint?: string;
}) {
  const caption = [source, periodLabel, hint].filter(Boolean).join(" · ");
  return (
    <div>
      <KpiCard label={label} value={value} delta={delta} series={series} color={color} />
      {caption && <p className="mt-1 px-1 text-[11px] text-muted">{caption}</p>}
    </div>
  );
}

function capitalize(s?: string | null): string {
  if (!s) return "—";
  return s.charAt(0).toUpperCase() + s.slice(1);
}

export default function Overview() {
  // Pinned useDashboard contract: `period` + `rangeQuery` ("" or "&from=..&to=..")
  // carry the dashboard's date-range controls into every fetch URL below.
  const { siteId, period, sites, rangeQuery = "" } = useDashboard();
  const site = sites.find((s) => s.id === siteId);

  // Pinned contract: every fetch URL carries the dashboard range controls.
  const periodQ = (path: string) =>
    `${path}${path.includes("?") ? "&" : "?"}period=${period}${rangeQuery}`;
  const periodDeps = [period, rangeQuery];

  const seo = useApi<any>(siteId ? periodQ(`/sites/${siteId}/analytics/seo/overview`) : null, periodDeps);
  const health = useApi<any>(siteId ? periodQ(`/sites/${siteId}/health`) : null, periodDeps);
  const perf = useApi<any>(siteId ? periodQ(`/sites/${siteId}/analytics/performance/latest?strategy=mobile`) : null, periodDeps);
  const visibility = useApi<any>(siteId ? periodQ(`/sites/${siteId}/ai-visibility`) : null, periodDeps);
  const uptime = useApi<any>(siteId ? periodQ(`/sites/${siteId}/uptime`) : null, periodDeps);
  const alertsApi = useApi<any>(siteId ? periodQ(`/sites/${siteId}/alerts?status=open`) : null, periodDeps);
  const ins = useApi<any>(siteId ? periodQ(`/sites/${siteId}/insights`) : null, periodDeps);

  // Commerce is demoted below search/performance and only fetched for sites
  // the woo gate below considers commerce-capable.
  const wooGateOpen = Boolean(site?.wc_version);
  const comOv = useApi<any>(wooGateOpen && siteId ? periodQ(`/sites/${siteId}/analytics/overview`) : null, periodDeps);
  const comRev = useApi<any>(wooGateOpen && siteId ? periodQ(`/sites/${siteId}/analytics/revenue`) : null, periodDeps);

  const [busy, setBusy] = useState(false);
  const [genErr, setGenErr] = useState<string | null>(null);

  if (!siteId)
    return (
      <EmptyState
        icon={<Package size={20} />}
        title="Add your first website"
        hint="Import from Google Search Console or just enter a URL — audits, uptime and PageSpeed start immediately. Any platform works."
        action={<Button onClick={() => (window.location.href = "/dashboard/add-website")}>Add website</Button>}
      />
    );

  const periodLabel = rangeQuery ? "Custom range" : PERIOD_LABEL[period] || period;
  const cur = site?.currency as string | undefined;

  // --- Search (primary) ----------------------------------------------------
  const seoM = seo.data?.metrics || null;
  const seoD = seo.data?.deltas || {};
  const seoSeries = seo.data?.series || [];
  const spark = (key: string) => seoSeries.map((r: any) => ({ v: r[key] || 0 }));
  // The seo/overview payload exposes the data window (range.to); when it ends
  // before today the Search Console sync has not caught up — surface that as
  // staleness instead of presenting old numbers as current.
  const today = new Date().toISOString().slice(0, 10);
  const staleThrough: string | null =
    seo.data?.range?.to && String(seo.data.range.to) < today ? String(seo.data.range.to) : null;

  // --- Site health / performance --------------------------------------------
  const hSite = health.data?.site || null;
  const connected = hSite?.connection_status === "connected";
  // REAL health (spec §19): crawl + SSL + uptime composite from the health
  // endpoint — never the WordPress connector status.
  const healthComposite = health.data?.health || null;
  const healthValue = health.loading
    ? "…"
    : healthComposite?.score != null
      ? String(healthComposite.score)
      : "—";
  const healthLabel =
    healthComposite?.state === "ok"
      ? "Good"
      : healthComposite?.state === "warning"
        ? "Needs work"
        : healthComposite?.state === "critical"
          ? "Critical"
          : healthComposite?.state === "no_audit"
            ? "No audit yet"
            : "—";
  const run = perf.data?.run || null;

  // --- Priority actions ------------------------------------------------------
  const openAlerts = (alertsApi.data?.alerts || []).slice(0, 3);
  const actionInsights = (ins.data?.insights || []).slice(0, Math.max(0, 3 - openAlerts.length));
  const actions = [
    ...openAlerts.map((a: any) => ({
      key: `alert-${a.id}`, tone: SEV_TONE[a.severity] || "slate",
      title: a.title, note: a.message || "Open the alerts page for details.", href: "/dashboard/alerts",
    })),
    ...actionInsights.map((i: any) => ({
      key: `insight-${i.id}`, tone: SEV_TONE[i.severity] || "slate",
      title: i.title, note: i.recommendation || i.summary || "Open the insights page for details.",
      href: "/dashboard/insights",
    })),
  ];

  const topInsight = (ins.data?.insights || [])[0];

  async function generateSummary() {
    setBusy(true);
    setGenErr(null);
    try {
      await apiPost(`/sites/${siteId}/insights/generate`, { type: "weekly" });
      ins.reload();
    } catch (e: any) {
      // Plan limits return a structured 402 here. Swallowing it left the
      // button looking like it had worked while nothing was generated.
      setGenErr(e?.message || "Could not generate a summary right now.");
    } finally {
      setBusy(false);
    }
  }

  // --- WooCommerce gate ------------------------------------------------------
  // `woocommerce_active` never reaches the client: the Site type carries no
  // such field (lib/types.ts) and /sites selects only id, name, domain,
  // platform, connection_status, last_sync_at, wc_version, plugin_version
  // (SiteController::index). The commerce analytics payload exposes no woo
  // flag either (AnalyticsController::overview returns range/metrics/previous/
  // deltas). `wc_version` is populated by the WooCommerce connector sync, so a
  // non-null value is the strongest client-side commerce signal; fall back to
  // live commerce activity in the period for robustness.
  const comM = comOv.data?.metrics || null;
  const comD = comOv.data?.deltas || {};
  const comSeries = comRev.data?.series || [];
  const commerceActive = (comM?.revenue ?? 0) > 0 || (comM?.orders ?? 0) > 0;
  const isWoo = Boolean(site?.wc_version) || commerceActive;

  return (
    <div>
      <OnboardingChecklist />
      <PageHeader
        title="Overview"
        subtitle={`${site?.name || ""} · ${periodLabel}${site?.last_sync_at ? ` · last synced ${timeAgo(site.last_sync_at)}` : ""}`}
      />

      {/* 1. KPI row — cross-source health at a glance */}
      <div className="grid grid-cols-2 gap-3.5 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-7">
        <Tile
          label="Site health"
          value={healthValue}
          hint={healthLabel}
          source={healthComposite?.sources?.health === "site_audit" ? "site audit" : "none"}
          periodLabel={periodLabel}
        />
        <Tile
          label="Organic clicks"
          value={seo.loading ? "…" : seo.error || !seoM ? "—" : num(seoM.clicks)}
          delta={seo.error || !seoM ? undefined : (seoD.clicks ?? null)}
          series={seo.error ? undefined : spark("clicks")}
          source="GSC"
          periodLabel={periodLabel}
        />
        <Tile
          label="Search impressions"
          value={seo.loading ? "…" : seo.error || !seoM ? "—" : num(seoM.impressions)}
          delta={seo.error || !seoM ? undefined : (seoD.impressions ?? null)}
          series={seo.error ? undefined : spark("impressions")}
          color="#6C5CE7"
          source="GSC"
          periodLabel={periodLabel}
        />
        <Tile
          label="Avg position"
          value={seo.loading ? "…" : !seoM ? "—" : seoM.avg_position != null ? Number(seoM.avg_position).toFixed(1) : "—"}
          delta={!seoM || seo.error ? undefined : seoD.avg_position != null ? -seoD.avg_position : null}
          source="GSC"
          periodLabel={periodLabel}
        />
        <Tile
          label="AI answer visibility"
          value={visibility.loading ? "…" : visibility.error || !visibility.data?.metrics ? "—" : `${visibility.data.metrics.mention_rate}%`}
          source="configured AI provider"
          periodLabel={periodLabel}
          hint={visibility.data?.metrics?.runs_total != null ? `${visibility.data.metrics.runs_total} runs` : undefined}
        />
        <Tile
          label="Performance score"
          value={perf.loading ? "…" : perf.error || !run ? "—" : run.performance_score != null ? String(run.performance_score) : "—"}
          source="Lighthouse · mobile"
          periodLabel={periodLabel}
        />
        <Tile
          label="Uptime"
          value={uptime.loading ? "…" : uptime.error || !uptime.data?.has_monitor ? "—" : uptime.data.availability_pct != null ? `${uptime.data.availability_pct}%` : "—"}
          source="HTTP monitor"
          periodLabel="last 30 days"
          hint={uptime.data?.incidents?.filter((i: any) => !i.recovered_at).length ? "open incident" : undefined}
        />
      </div>

      {/* 2. Search trend */}
      <Card
        className="mt-5"
        title="Search trend"
        subtitle={`Clicks & impressions · ${periodLabel}`}
        right={staleThrough ? <StaleBadge label={`Data through ${staleThrough}`} /> : undefined}
      >
        {seo.error ? (
          <ErrorState title="Search data unavailable" message={seo.error} onRetry={seo.reload} />
        ) : seo.loading ? (
          <Spinner />
        ) : !seo.data?.has_data || seoSeries.length < 2 ? (
          <p className="py-10 text-center text-sm text-muted">
            No search data yet — connect Search Console (directly or via the Google account above) and metrics appear here after the first sync.
          </p>
        ) : (
          <div className="space-y-4">
            <div>
              <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Clicks</p>
              <AreaTrend data={seoSeries} dataKey="clicks" height={200} />
            </div>
            <div>
              <p className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted">Impressions</p>
              <LineTrend data={seoSeries} dataKey="impressions" color="#6C5CE7" height={160} />
            </div>
          </div>
        )}
      </Card>

      {/* 3. Priority actions + AI summary */}
      <div className="mt-5 grid gap-5 lg:grid-cols-3">
        <Card
          className="lg:col-span-2"
          title="Priority actions"
          subtitle="Open alerts and the latest AI insights"
          right={
            <Link href="/dashboard/alerts" className="text-xs font-semibold text-brand hover:underline">
              View alerts
            </Link>
          }
        >
          {alertsApi.error ? (
            <ErrorState title="Alerts unavailable" message={alertsApi.error} onRetry={alertsApi.reload} />
          ) : alertsApi.loading ? (
            <Spinner />
          ) : actions.length === 0 ? (
            <p className="py-10 text-center text-sm text-muted">No open alerts right now — all clear.</p>
          ) : (
            <div className="space-y-2.5">
              {actions.map((a) => (
                <Link key={a.key} href={a.href} className="flex items-start gap-3 rounded-xl border border-line p-3 transition hover:bg-card2">
                  <span className={`mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg ${
                    a.tone === "red" ? "bg-bad/12 text-bad" : a.tone === "amber" ? "bg-warn/15 text-warn" : "bg-black/5 text-muted"
                  }`}>
                    {a.href === "/dashboard/alerts" ? <AlertTriangle size={14} /> : <Lightbulb size={14} />}
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium text-fg">{a.title}</span>
                    <span className="block truncate text-xs text-muted">{a.note}</span>
                  </span>
                </Link>
              ))}
            </div>
          )}
        </Card>

        <Card
          title="AI summary"
          subtitle="Plain-language read on what changed"
          right={
            <button
              onClick={generateSummary}
              disabled={busy}
              className="inline-flex items-center gap-1.5 rounded-lg bg-violet px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90 disabled:opacity-50"
            >
              <Sparkles size={13} /> {busy ? "Generating…" : "Generate"}
            </button>
          }
        >
          {genErr && (
            <div className="mb-3 rounded-xl border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{genErr}</div>
          )}
          {ins.error ? (
            <ErrorState message={ins.error} onRetry={ins.reload} />
          ) : ins.loading ? (
            <Spinner />
          ) : topInsight ? (
            <>
              <p className="text-sm leading-relaxed text-fg">
                <span className="font-semibold">{topInsight.title}.</span> {topInsight.summary}
              </p>
              {topInsight.recommendation && (
                <p className="mt-2 text-xs text-muted">
                  <span className="font-semibold text-violet">Next action:</span> {topInsight.recommendation}
                </p>
              )}
              <Link href="/dashboard/insights" className="mt-3 inline-block text-xs font-semibold text-brand hover:underline">
                View all insights
              </Link>
            </>
          ) : (
            <p className="text-sm text-muted">
              No AI summary yet for {site?.name || "this site"}. Click “Generate” for a summary of what changed, why it
              matters, and what to do next.
            </p>
          )}
        </Card>
      </div>

      {/* 4. Technical & performance strip */}
      <Card className="mt-5" title="Technical & performance" subtitle={`Lighthouse, connector and sync essentials · ${periodLabel}`}>
        <div className="grid gap-4 sm:grid-cols-3">
          <div>
            {health.error ? (
              <ErrorState title="Connector status unavailable" message={health.error} onRetry={health.reload} />
            ) : health.loading ? (
              <Spinner />
            ) : (
              <>
                <div className="flex items-center gap-2">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-muted">Connector</h3>
                  <Badge tone={connected ? "green" : "amber"} dot>{connected ? "Connected" : hSite?.connection_status === "not_installed" ? "Connector not installed" : capitalize(hSite?.connection_status)}</Badge>
                </div>
                <p className="mt-2 text-sm text-muted">
                  Last sync {hSite?.last_sync_at ? timeAgo(hSite.last_sync_at) : "—"}
                </p>
                <Link href="/dashboard/site-health" className="mt-1 inline-block text-xs font-semibold text-brand hover:underline">
                  Site health details
                </Link>
              </>
            )}
          </div>
          <div>
            {perf.error ? (
              <ErrorState title="Performance data unavailable" message={perf.error} onRetry={perf.reload} />
            ) : perf.loading ? (
              <Spinner />
            ) : !run ? (
              <p className="text-sm text-muted">No PageSpeed run yet for this site.</p>
            ) : (
              <>
                <div className="flex items-center gap-2">
                  <h3 className="text-xs font-semibold uppercase tracking-wide text-muted">Lighthouse · lab</h3>
                  <span className="text-lg font-bold text-fg">
                    {run.performance_score != null ? run.performance_score : "—"}
                  </span>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {[
                    { label: "LCP", status: run.lcp_status },
                    { label: "INP", status: run.inp_status },
                    { label: "CLS", status: run.cls_status },
                  ].map((v) => (
                    <Badge key={v.label} tone={CWV_TONE[v.status] ?? "slate"}>
                      {v.label} {v.status ? v.status.replace(/_/g, " ") : "—"}
                    </Badge>
                  ))}
                </div>
              </>
            )}
          </div>
          <div className="flex flex-col justify-between gap-2">
            <SourceFreshness label="Last PageSpeed run" asOf={run?.run_at} />
            <Link href="/dashboard/performance" className="text-xs font-semibold text-brand hover:underline">
              Performance details
            </Link>
          </div>
        </div>
      </Card>

      {/* 5. Commerce — only for WooCommerce sites (see gate above) */}
      {isWoo && (
        <Card
          className="mt-5"
          title="Commerce"
          subtitle={`WooCommerce essentials · ${periodLabel}`}
          right={
            <Link href="/dashboard/revenue" className="text-xs font-semibold text-brand hover:underline">
              Revenue details
            </Link>
          }
        >
          {comOv.error ? (
            <ErrorState title="Commerce data unavailable" message={comOv.error} onRetry={comOv.reload} />
          ) : comOv.loading ? (
            <Spinner />
          ) : (
            <>
              <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
                <KpiCard label="Revenue" value={comM ? money0(comM.revenue, cur) : "—"} delta={comD.revenue ?? undefined} series={comSeries.map((r: any) => ({ v: r.revenue || 0 }))} />
                <KpiCard label="Orders" value={comM ? num(comM.orders) : "—"} delta={comD.orders ?? undefined} color="#6C5CE7" />
                <KpiCard label="Avg order value" value={comM ? money(comM.average_order_value, cur) : "—"} delta={comD.average_order_value ?? undefined} />
                <KpiCard label="Refunds" value={comM ? num(comM.refunds) : "—"} delta={comD.refunds ?? undefined} color="#EF5350" />
              </div>
              {comSeries.length > 1 && (
                <div className="mt-4">
                  <AreaTrend data={comSeries} dataKey="revenue" height={180} />
                </div>
              )}
            </>
          )}
        </Card>
      )}
    </div>
  );
}
