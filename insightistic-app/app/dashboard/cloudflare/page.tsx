"use client";
import { useState } from "react";
import { useRouter } from "next/navigation";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { AreaTrend, LineTrend } from "@/components/dashboard/Charts";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { num } from "@/lib/format";
import { Globe } from "lucide-react";

/**
 * Edge traffic, cache, security and available crawler matches from the
 * nightly Cloudflare sync.
 */

const DAY_OPTIONS: { days: number; label: string }[] = [
  { days: 7, label: "7 days" },
  { days: 30, label: "30 days" },
  { days: 90, label: "90 days" },
];

/** Segmented 7 / 30 / 90 day chips — the Cloudflare window is its own range, independent of the global period. */
function DaysToggle({ value, onChange }: { value: number; onChange: (d: number) => void }) {
  return (
    <div className="flex flex-wrap gap-2" aria-label="Cloudflare range">
      {DAY_OPTIONS.map((o) => (
        <button
          key={o.days}
          type="button"
          aria-pressed={value === o.days}
          onClick={() => onChange(o.days)}
          className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
            value === o.days ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
          }`}
        >
          {o.label}
        </button>
      ))}
    </div>
  );
}

type BotRow = { bot_name: string; classification: string; requests: number };

const BOT_COLUMNS: Column<BotRow>[] = [
  { key: "bot_name", label: "User agent", primary: true, render: (r) => <span className="text-sm font-medium">{r.bot_name}</span> },
  { key: "requests", label: "Matched requests", align: "right", render: (r) => num(r.requests) },
];

function firewallTone(action: string): "red" | "amber" | "slate" {
  if (action === "block") return "red";
  if (action.includes("challenge")) return "amber";
  return "slate";
}

function humanAction(raw: string): string {
  return raw.replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

export default function CloudflarePage() {
  const { siteId } = useDashboard();
  const router = useRouter();
  const [days, setDays] = useState(30);

  // One payload for the whole page (CloudflareController::overview) — the
  // nightly sync fills the tables; this endpoint never calls Cloudflare live.
  const cf = useApi<any>(siteId ? `/sites/${siteId}/cloudflare?days=${days}` : null, [days]);

  if (!siteId)
    return (
      <EmptyState
        icon={<Globe size={20} />}
        title="No website yet"
        hint="Add a website and connect Cloudflare to see its edge intelligence here."
      />
    );

  if (cf.loading) return <Spinner />;

  // A failed fetch is not the same as "no edge data" — say so and retry.
  if (cf.error)
    return (
      <div>
        <PageHeader title="Cloudflare Edge" subtitle="Edge traffic, cache, and security." right={<DaysToggle value={days} onChange={setDays} />} />
        <ErrorState message={cf.error} onRetry={cf.reload} />
      </div>
    );

  const d = cf.data || {};

  // No synced rows yet: an expected, calm state with a connect hint.
  if (d.has_data === false) {
    if (d.hint === "connected_awaiting_sync") {
      return (
        <div>
          <PageHeader title="Cloudflare Edge" subtitle="Edge traffic, cache, and security." right={<DaysToggle value={days} onChange={setDays} />} />
          <EmptyState
            icon={<Globe size={20} />}
            title="Connected — first edge sync will fill this within a day."
            hint={d.message || "Cloudflare is connected; edge analytics appear after the nightly sync fills them."}
          />
        </div>
      );
    }
    return (
      <div>
        <PageHeader title="Cloudflare Edge" subtitle="Edge traffic, cache, and security." right={<DaysToggle value={days} onChange={setDays} />} />
        <EmptyState
          icon={<Globe size={20} />}
          title="Connect Cloudflare in Integrations"
          hint={d.message || "Connect Cloudflare (Zone Analytics: Read token + zone) to start collecting edge intelligence."}
          action={<Button onClick={() => router.push("/dashboard/integrations")}>Open Integrations</Button>}
        />
      </div>
    );
  }

  // Defensive destructuring — sections may be absent; nothing here should
  // crash on a missing key.
  const ov = d.overview || {};
  const trend: any[] = ov.trend || [];
  const trendHasCached = trend.some((t) => t?.cached_requests != null);
  const geoRows: { country: string; requests: number }[] = (Array.isArray(d.geo) ? d.geo : d.geo?.top_countries) || [];
  const sec = d.security || {};
  const secTrend: any[] = sec.trend || [];
  const actions: Record<string, number> = sec.actions || {};
  const bots: { available?: boolean; rows?: BotRow[] } | undefined = d.bots;
  const aiCrawlerRows = bots?.available === true
    ? (bots.rows ?? []).filter((row) => row.classification === "ai_crawler" && row.requests > 0)
    : [];
  const gap: {
    edge_requests?: number;
    ga4_sessions?: number;
    gap_ratio_pct?: number | null;
    explanations?: Record<string, string> | string[];
  } | null = d.traffic_gap ?? null;
  const freshness = d.freshness || null;

  // Nulls render as "—" — the backend nulls ratios when the denominator is 0.
  const int = (v: unknown) => (v == null ? "—" : num(Number(v)));
  const dec = (v: unknown) => (v == null ? "—" : Number(v).toLocaleString(undefined, { maximumFractionDigits: 2 }));
  const ratio = (v: unknown) => (v == null ? "—" : `${Number(v)}%`);

  const geoMax = Math.max(1, ...geoRows.map((g) => Number(g.requests) || 0));

  // Doc 15: the explanation strings ARE the traffic-gap feature. The backend
  // returns a keyed map; accept a plain array too.
  const gapExplanations: string[] = gap
    ? Array.isArray(gap.explanations)
      ? gap.explanations
      : Object.values(gap.explanations || {})
    : [];

  return (
    <div>
      <PageHeader
        title="Cloudflare Edge"
        subtitle="Edge traffic, cache, and security."
        right={
          <div className="flex flex-wrap items-center gap-3">
            <SourceFreshness label="Last edge data" asOf={freshness?.last_data_date ?? null} />
            <DaysToggle value={days} onChange={setDays} />
          </div>
        }
      />

      {/* Traffic & Edge Overview KPIs (doc 15) */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        <KpiCard label="Requests" value={int(ov.requests)} />
        <KpiCard label="Bandwidth (GB)" value={dec(ov.bandwidth_gb)} />
        <KpiCard label="Cache hit ratio" value={ratio(ov.cache_hit_ratio)} />
        <KpiCard label="Bandwidth saved" value={ratio(ov.bandwidth_saved_pct)} />
        <KpiCard label="Encrypted" value={ratio(ov.encrypted_pct)} />
        <KpiCard label="Pageviews" value={int(ov.pageviews)} />
        <KpiCard label="Threats" value={int(ov.threats)} />
        <KpiCard label="4xx" value={int(ov.client_4xx)} />
        <KpiCard label="5xx" value={int(ov.client_5xx)} />
      </div>

      <div className={`mt-5 grid gap-5 ${trendHasCached ? "lg:grid-cols-2" : ""}`}>
        <Card title="Requests trend">
          {trend.length > 1 ? (
            <AreaTrend data={trend} dataKey="requests" height={240} />
          ) : (
            <p className="py-8 text-center text-sm text-muted">Not enough data yet for a trend.</p>
          )}
        </Card>
        {trendHasCached && (
          <Card
            title="Cached requests trend"
            subtitle="Served from Cloudflare's cache — uncached = requests − cached"
          >
            <LineTrend data={trend} dataKey="cached_requests" color="#6C5CE7" height={240} />
          </Card>
        )}
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        {/* Security: threat trend + firewall actions (doc 15) */}
        <Card title="Security" subtitle={`${int(sec.threats_total ?? ov.threats)} threats in range`}>
          <div className="grid gap-5 sm:grid-cols-2">
            <div>
              <p className="mb-2 text-xs font-medium text-muted">Threats per day</p>
              {secTrend.length > 1 ? (
                <LineTrend data={secTrend} dataKey="threats" color="#F6A609" height={160} />
              ) : (
                <p className="py-6 text-center text-xs text-muted">Not enough data yet for a threat trend.</p>
              )}
            </div>
            <div>
              <p className="mb-2 text-xs font-medium text-muted">Firewall actions</p>
              {Object.keys(actions).length === 0 ? (
                <p className="py-6 text-center text-xs text-muted">No firewall events in range.</p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {Object.entries(actions).map(([action, count]) => (
                    <Badge key={action} tone={firewallTone(action)}>
                      {humanAction(action)} · {num(count)}
                    </Badge>
                  ))}
                </div>
              )}
            </div>
          </div>
        </Card>

        {/* Geographic traffic: top countries with bars relative to the max */}
        <Card title="Top countries" subtitle="Edge requests by country">
          {geoRows.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted">No geographic data in range.</p>
          ) : (
            <div className="space-y-2.5">
              {geoRows.map((g) => {
                const reqs = Number(g.requests) || 0;
                const width = Math.max(2, Math.round((reqs / geoMax) * 100));
                return (
                  <div key={g.country}>
                    <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                      <span className="font-mono font-medium text-fg">{g.country}</span>
                      <span className="text-muted">{num(reqs)}</span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-card2">
                      <div className="h-full rounded-full bg-brand" style={{ width: `${width}%` }} />
                    </div>
                  </div>
                );
              })}
            </div>
          )}
        </Card>
      </div>

      {aiCrawlerRows.length > 0 && (
        <Card
          className="mt-5"
          title="AI crawlers"
          subtitle="Cloudflare requests matching known AI crawler user agents. Crawler identity is unverified."
        >
          <ResponsiveTable
            columns={BOT_COLUMNS}
            rows={aiCrawlerRows}
            rowKey={(r) => r.bot_name}
          />
        </Card>
      )}

      {/* Traffic gap (doc 15): edge vs GA4, deliberately never equated */}
      {gap && (
        <Card
          className="mt-5"
          title="Traffic gap"
          subtitle="Edge requests and GA4 sessions measure different units — shown side by side, never equated"
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="rounded-2xl border border-line bg-card2 p-4">
              <p className="text-xs font-medium text-muted">Edge requests (Cloudflare)</p>
              <p className="mt-1 text-[26px] font-bold leading-tight text-fg">{int(gap.edge_requests)}</p>
              <p className="mt-1 text-xs text-muted">Every asset served through Cloudflare</p>
            </div>
            <div className="rounded-2xl border border-line bg-card2 p-4">
              <p className="text-xs font-medium text-muted">GA4 sessions (Analytics)</p>
              <p className="mt-1 text-[26px] font-bold leading-tight text-fg">{int(gap.ga4_sessions)}</p>
              {gap.gap_ratio_pct != null && (
                <p className="mt-1 text-xs text-muted">Sessions are {Number(gap.gap_ratio_pct)}% of edge requests</p>
              )}
            </div>
          </div>

          {gapExplanations.length > 0 && (
            <div className="mt-5">
              <p className="text-sm font-semibold text-fg">Why these numbers differ</p>
              <ul className="mt-2 list-disc space-y-1.5 pl-5 text-sm text-muted">
                {gapExplanations.map((line, i) => (
                  <li key={i}>{line}</li>
                ))}
              </ul>
            </div>
          )}
        </Card>
      )}
    </div>
  );
}
