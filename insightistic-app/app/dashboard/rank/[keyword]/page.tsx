"use client";
import { useParams } from "next/navigation";
import Link from "next/link";
import { CartesianGrid, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { ArrowLeft, Target } from "lucide-react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Spinner } from "@/components/ui/Spinner";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState } from "@/components/dashboard/states";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { PathCell } from "@/components/dashboard/search";
import { PositionCell } from "@/components/dashboard/rank";
import { urlPath } from "@/components/dashboard/audit";
import { timeAgo } from "@/lib/format";

/**
 * Keyword position history (roadmap doc 13): the controlled SERP observations
 * for one tracked keyword. GSC impressions deliberately don't appear here —
 * the section label keeps tracker positions and Search Console signals
 * separate, never one unnamed "position" metric.
 */

type HistoryEntry = {
  checked_at: string;
  position: number | null;
  ranking_url: string | null;
  cost_units: number | null;
};

type RankKeyword = {
  id: number | string;
  keyword: string;
  engine: string;
  country: string;
  device: string;
  location?: string | null;
  last_checked_at?: string | null;
  latest?: { position: number | null; ranking_url: string | null; checked_at: string | null } | null;
};

const tipStyle = {
  background: "var(--ins-card)",
  border: "1px solid var(--ins-line)",
  borderRadius: 10,
  color: "var(--ins-fg)",
  fontSize: 12,
};

function shortDate(iso: string): string {
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return iso;
  return d.toLocaleDateString(undefined, { month: "short", day: "numeric" });
}

/** Real position in the tooltip; the axis is inverted so up always means better. */
function PositionTooltip({ active, payload }: any) {
  if (!active || !payload?.length) return null;
  const point = payload[0].payload as { date: string; position: number | null };
  return (
    <div className="rounded-xl px-3 py-2 shadow-card" style={tipStyle}>
      <p className="font-semibold">{point.position != null ? `#${point.position}` : "Not ranking"}</p>
      <p className="text-muted">{point.date}</p>
    </div>
  );
}

export default function RankKeywordHistoryPage() {
  const { keyword: id } = useParams<{ keyword: string }>();
  const { siteId } = useDashboard();

  const history = useApi<any>(siteId && id ? `/sites/${siteId}/rank/keywords/${id}/history` : null);
  // Identity comes from the tracker list so a stale or foreign id resolves to
  // an explicit not-found state instead of a half-empty page.
  const list = useApi<any>(siteId ? `/sites/${siteId}/rank` : null);

  if (!siteId)
    return (
      <EmptyState
        icon={<Target size={20} />}
        title="No website yet"
        hint="Add a website to track its keyword positions here."
      />
    );

  if (history.loading || list.loading)
    return (
      <div>
        <Link href="/dashboard/rank" className="mb-3 inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline">
          <ArrowLeft size={13} /> Back to Rank Tracker
        </Link>
        <Spinner />
      </div>
    );

  if (history.error)
    return (
      <div>
        <PageHeader title="Keyword history" subtitle="SERP positions (tracker)" />
        <ErrorState message={history.error} onRetry={history.reload} />
      </div>
    );

  const entries: HistoryEntry[] = Array.isArray(history.data?.history) ? history.data.history : [];
  const kw: RankKeyword | undefined = (list.data?.keywords?.data || []).find((k: any) => String(k.id) === String(id));

  if (!kw)
    return (
      <EmptyState
        icon={<Target size={20} />}
        title="Keyword not found"
        hint="This keyword isn't tracked for this site — it may have been removed. Head back to the Rank Tracker to see what's being tracked."
        action={
          <Link
            href="/dashboard/rank"
            className="inline-flex items-center gap-1 rounded-xl border border-line bg-card px-4 py-2 text-sm font-semibold text-fg hover:border-brand/40 hover:bg-card2"
          >
            <ArrowLeft size={14} /> Back to Rank Tracker
          </Link>
        }
      />
    );

  const points = entries.map((e) => ({ date: shortDate(e.checked_at), position: e.position }));
  const positions = entries.map((e) => e.position).filter((p): p is number => p != null);
  const maxPos = positions.length ? Math.max(...positions) : 100;

  const columns: Column<HistoryEntry>[] = [
    {
      key: "checked_at",
      label: "Checked",
      primary: true,
      render: (r) => (
        <span className="font-medium text-fg" title={r.checked_at}>
          {timeAgo(r.checked_at)}
        </span>
      ),
    },
    { key: "position", label: "Position", align: "right", render: (r) => <PositionCell position={r.position} /> },
    {
      key: "ranking_url",
      label: "Ranking URL",
      render: (r) => (r.ranking_url ? <PathCell path={urlPath(r.ranking_url)} /> : <span className="text-muted">—</span>),
    },
    { key: "cost_units", label: "Cost", align: "right", render: (r) => (r.cost_units != null ? Number(r.cost_units).toFixed(2) : "—") },
  ];

  return (
    <div>
      <Link href="/dashboard/rank" className="mb-3 inline-flex items-center gap-1 text-xs font-semibold text-brand hover:underline">
        <ArrowLeft size={13} /> Back to Rank Tracker
      </Link>

      <PageHeader
        title={kw.keyword}
        right={
          <div className="text-right">
            <PositionCell position={kw.latest?.position ?? null} />
            <p className="mt-1 text-xs text-muted">latest</p>
          </div>
        }
      />
      <div className="-mt-4 mb-5 flex flex-wrap items-center gap-2">
        {[kw.engine, kw.device, kw.country, kw.location].filter(Boolean).map((chip) => (
          <Badge key={chip as string} tone="slate">
            {chip}
          </Badge>
        ))}
      </div>

      <Card title="SERP positions (tracker)" subtitle="Separate from GSC impressions — Search Console averages real-impression positions, these are controlled observations.">
        {entries.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted">No checks recorded yet — run a check from the Rank Tracker.</p>
        ) : (
          <>
            <p className="mb-2 text-xs text-muted">Position (inverted — top = #1)</p>
            <ResponsiveContainer width="100%" height={260}>
              <LineChart data={points} margin={{ top: 8, right: 8, bottom: 0, left: 0 }}>
                <CartesianGrid vertical={false} stroke="var(--ins-line)" />
                <XAxis dataKey="date" tick={{ fill: "var(--ins-muted)", fontSize: 11 }} axisLine={false} tickLine={false} minTickGap={24} />
                <YAxis reversed domain={[1, maxPos + 1]} tick={{ fill: "var(--ins-muted)", fontSize: 11 }} axisLine={false} tickLine={false} width={44} />
                <Tooltip content={<PositionTooltip />} />
                <Line type="monotone" dataKey="position" stroke="#00C04B" strokeWidth={2.4} dot={{ r: 2.5 }} connectNulls={false} />
              </LineChart>
            </ResponsiveContainer>
          </>
        )}
      </Card>

      <Card className="mt-4" title="Observations" subtitle={`${entries.length} check${entries.length === 1 ? "" : "s"} — last 90 days`}>
        <ResponsiveTable
          columns={columns}
          rows={entries}
          rowKey={(r, i) => `${r.checked_at}-${i}`}
          emptyMessage="No checks recorded yet — run a check from the Rank Tracker."
        />
      </Card>
    </div>
  );
}
