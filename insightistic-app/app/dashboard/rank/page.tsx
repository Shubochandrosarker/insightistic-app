"use client";
import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { Download, ListPlus, RefreshCcw, Target } from "lucide-react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDelete, apiDownload, apiGet, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { DeltaCell, PathCell } from "@/components/dashboard/search";
import { AddKeywordsDialog, PositionCell, PROVIDER_NOT_CONNECTED_MSG, ProviderBanner } from "@/components/dashboard/rank";
import { urlPath } from "@/components/dashboard/audit";
import { num } from "@/lib/format";

/**
 * Rank Tracker overview (roadmap doc 13): daily Google positions for tracked
 * keywords. Tracker positions are controlled point-in-time SERP observations
 * for a locale/device; GSC average position is aggregated over real
 * impressions. The two live in separate columns with separate labels and are
 * never merged into one unnamed "position" metric.
 */

type RankKeyword = {
  id: number | string;
  keyword: string;
  engine: string;
  country: string;
  device: string;
  location?: string | null;
  last_checked_at: string | null;
  latest: { position: number | null; ranking_url: string | null; checked_at: string | null } | null;
  previous_position: number | null;
  best_position: number | null;
  impressions_28d: number | null;
  cost_30d: number | null;
};

type RankKpis = {
  tracked: number;
  active: number;
  top3: number;
  top10: number;
  top20: number;
  not_found: number;
  improved: number;
  declined: number;
  visibility: number | null;
};

type Suggestion = { query: string; impressions: number; clicks: number; tracked: boolean };

const isSameDay = (iso: string | null | undefined): boolean => {
  if (!iso) return false;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return false;
  const now = new Date();
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
};

export default function RankTrackerPage() {
  const { siteId } = useDashboard();

  // First page streams through useApi; "Load more" fetches the next cursor
  // and appends — same idiom as the Search Intelligence explorers.
  const first = useApi<any>(siteId ? `/sites/${siteId}/rank` : null);
  const [extra, setExtra] = useState<RankKeyword[]>([]);
  const [cursor, setCursor] = useState<string | null>(null);
  const [hasMore, setHasMore] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);

  const suggestionsApi = useApi<any>(siteId ? `/sites/${siteId}/rank/suggestions` : null);
  const [suggestionsOpen, setSuggestionsOpen] = useState(true);

  const [msg, setMsg] = useState<{ tone: "info" | "warn" | "bad"; text: string } | null>(null);
  const [checkingAll, setCheckingAll] = useState(false);
  const [busyRow, setBusyRow] = useState<string | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);

  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);

  useEffect(() => {
    setExtra([]);
    setMoreError(null);
    setCursor(first.data?.keywords?.meta?.next_cursor ?? null);
    setHasMore(!!first.data?.keywords?.meta?.has_more);
  }, [first.data]);

  const kpis: RankKpis | null = first.data?.kpis ?? null;
  const providerConfigured = Boolean(first.data?.provider_configured);
  const rows: RankKeyword[] = [...(first.data?.keywords?.data || []), ...extra];
  const notFoundKpis = first.data?.kpis?.not_found;

  function reloadAll() {
    first.reload();
    suggestionsApi.reload();
  }

  async function loadMore() {
    if (!siteId || !cursor || loadingMore) return;
    setLoadingMore(true);
    setMoreError(null);
    try {
      const next = await apiGet(`/sites/${siteId}/rank?cursor=${encodeURIComponent(cursor)}`);
      setExtra((prev) => [...prev, ...(next?.keywords?.data || [])]);
      setCursor(next?.keywords?.meta?.next_cursor ?? null);
      setHasMore(!!next?.keywords?.meta?.has_more);
    } catch (e: any) {
      setMoreError(e?.message || "Couldn't load more keywords.");
    } finally {
      setLoadingMore(false);
    }
  }

  function checkAllNow() {
    if (!siteId || checkingAll) return;
    setCheckingAll(true);
    setMsg(null);
    apiPost(`/sites/${siteId}/rank/check`, {})
      .then((res: any) => {
        if (!alive.current) return;
        setCheckingAll(false);
        const n = num(res?.keywords ?? 0);
        setMsg({ tone: "info", text: `Checking ${n} keyword${n === "1" ? "" : "s"} — results refresh in a few seconds.` });
        // Checks are queued server-side; one refetch after ~10s is enough.
        setTimeout(() => {
          if (alive.current) reloadAll();
        }, 10_000);
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setCheckingAll(false);
        if (e?.data?.code === "rank_provider_not_configured") {
          setMsg({ tone: "warn", text: PROVIDER_NOT_CONNECTED_MSG });
        } else if (e?.status === 403) {
          setMsg({ tone: "warn", text: "You don't have permission to run rank checks — ask a site owner or admin." });
        } else {
          setMsg({ tone: "bad", text: e?.message || "Couldn't start the check. Try again in a moment." });
        }
      });
  }

  function recheck(row: RankKeyword) {
    if (!siteId || busyRow) return;
    setBusyRow(`check-${row.id}`);
    setMsg(null);
    apiPost(`/sites/${siteId}/rank/keywords/${row.id}/check`, {})
      .then(() => {
        if (!alive.current) return;
        setBusyRow(null);
        setMsg({ tone: "info", text: `Check queued for "${row.keyword}" — refresh in a few seconds.` });
        setTimeout(() => {
          if (alive.current) first.reload();
        }, 10_000);
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setBusyRow(null);
        if (e?.data?.code === "already_checked_today") {
          setMsg({ tone: "warn", text: `"${row.keyword}" was already checked today.` });
        } else if (e?.data?.code === "rank_provider_not_configured") {
          setMsg({ tone: "warn", text: PROVIDER_NOT_CONNECTED_MSG });
        } else {
          setMsg({ tone: "bad", text: e?.message || "Couldn't queue the check. Try again in a moment." });
        }
      });
  }

  function remove(row: RankKeyword) {
    if (!siteId || busyRow) return;
    if (!globalThis.confirm(`Stop tracking "${row.keyword}"? Its position history stays on record.`)) return;
    setBusyRow(`delete-${row.id}`);
    setMsg(null);
    apiDelete(`/sites/${siteId}/rank/keywords/${row.id}`)
      .then(() => {
        if (!alive.current) return;
        setBusyRow(null);
        setMsg({ tone: "info", text: `"${row.keyword}" removed from tracking — history kept.` });
        reloadAll();
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setBusyRow(null);
        setMsg({ tone: "bad", text: e?.message || "Couldn't remove the keyword. Try again in a moment." });
      });
  }

  function addSuggestions(queries: string[]) {
    if (!siteId || !queries.length) return;
    if (queries.length > 1 && !globalThis.confirm(`Add ${queries.length} suggested keywords to the tracker?`)) return;
    setMsg(null);
    apiPost(`/sites/${siteId}/rank/suggestions/confirm`, { queries })
      .then((res: any) => {
        if (!alive.current) return;
        setMsg({ tone: "info", text: `Added ${num(res?.created ?? 0)} keyword${num(res?.created ?? 0) === "1" ? "" : "s"} from Search Console suggestions.` });
        reloadAll();
      })
      .catch((e: any) => {
        if (!alive.current) return;
        if (e?.data?.code === "tracked_keyword_limit_reached") {
          const limit = e?.data?.limit || {};
          setMsg({ tone: "warn", text: `Keyword limit reached — ${num(limit.used)} of ${num(limit.max)} tracked keywords on your plan. Upgrade to track more.` });
        } else {
          setMsg({ tone: "bad", text: e?.message || "Couldn't add the suggestions. Try again in a moment." });
        }
      });
  }

  function exportCsv() {
    if (!siteId) return;
    setExportError(null);
    apiDownload(`/sites/${siteId}/rank.csv`, "rank-keywords.csv").catch((e: any) =>
      setExportError(e?.message || "Export failed."),
    );
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<Target size={20} />}
        title="No website yet"
        hint="Add a website to track its keyword positions here."
      />
    );

  const kpiList: { label: string; value: string | number }[] = kpis
    ? [
        { label: "Tracked (active)", value: num(kpis.active) },
        { label: "Top 3", value: num(kpis.top3) },
        { label: "Top 10", value: num(kpis.top10) },
        { label: "Top 20", value: num(kpis.top20) },
        { label: "Improved", value: num(kpis.improved) },
        { label: "Declined", value: num(kpis.declined) },
        { label: "Not found", value: num(notFoundKpis) },
        { label: "Visibility", value: kpis.visibility != null ? `${Math.round(Number(kpis.visibility))}/100` : "—" },
      ]
    : [];

  const columns: Column<RankKeyword>[] = [
    {
      key: "keyword",
      label: "Keyword",
      primary: true,
      render: (r) => (
        <div className="min-w-0">
          <span className="font-semibold text-fg">{r.keyword}</span>
          <span className="block text-xs text-muted">
            {[r.engine, r.device, r.country, r.location].filter(Boolean).join(" · ")}
          </span>
        </div>
      ),
    },
    { key: "position", label: "Position", align: "right", render: (r) => <PositionCell position={r.latest?.position ?? null} /> },
    {
      key: "delta",
      label: "Δ",
      align: "right",
      // Improvement is a lower position number — invert the delta tones.
      render: (r) =>
        r.latest?.position != null && r.previous_position != null ? (
          <DeltaCell value={r.latest.position - r.previous_position} invert />
        ) : (
          <DeltaCell value={null} />
        ),
    },
    { key: "best_position", label: "Best", align: "right", render: (r) => (r.best_position != null ? `#${r.best_position}` : "—") },
    {
      key: "ranking_url",
      label: "Ranking URL",
      render: (r) => (r.latest?.ranking_url ? <PathCell path={urlPath(r.latest.ranking_url)} /> : <span className="text-muted">—</span>),
    },
    {
      key: "impressions_28d",
      label: "GSC impr. 28d",
      align: "right",
      render: (r) => (
        <span title="Separate signal — Search Console impressions, never merged with tracker positions">
          {r.impressions_28d != null ? num(r.impressions_28d) : "—"}
        </span>
      ),
    },
    {
      key: "cost_30d",
      label: "Cost 30d",
      align: "right",
      render: (r) => (r.cost_30d != null ? Number(r.cost_30d).toFixed(2) : "—"),
    },
    {
      key: "last_checked_at",
      label: "Last checked",
      align: "right",
      render: (r) => <SourceFreshness label="Checked" asOf={r.last_checked_at} />,
    },
    {
      key: "actions",
      label: "Actions",
      align: "right",
      render: (r) => (
        <div className="flex items-center justify-end gap-1.5">
          <Link href={`/dashboard/rank/${r.id}`} className="text-xs font-semibold text-brand hover:underline" title="Position history">
            History
          </Link>
          <Button
            variant="ghost"
            className="px-2.5 py-1 text-xs"
            disabled={busyRow !== null || isSameDay(r.last_checked_at)}
            title={isSameDay(r.last_checked_at) ? "Already checked today" : "Check position now"}
            onClick={() => recheck(r)}
          >
            Re-check
          </Button>
          <Button
            variant="danger"
            className="px-2.5 py-1 text-xs"
            disabled={busyRow !== null}
            title="Stop tracking (history kept)"
            onClick={() => remove(r)}
          >
            Delete
          </Button>
        </div>
      ),
    },
  ];

  const suggestions: Suggestion[] = Array.isArray(suggestionsApi.data) ? suggestionsApi.data : [];

  return (
    <div>
      <PageHeader title="Rank Tracker" subtitle="Daily Google positions for the keywords that matter." />

      {first.data && <ProviderBanner configured={providerConfigured} />}

      {first.loading && !first.data ? (
        <Spinner />
      ) : first.error ? (
        <ErrorState message={first.error} onRetry={first.reload} />
      ) : rows.length === 0 ? (
        <>
          <EmptyState
            icon={<Target size={20} />}
            title="Track your first keywords"
            hint="Add the keywords you care about and Insightistic checks their Google position daily — one controlled SERP observation per keyword, per locale and device."
            action={<AddKeywordsDialog siteId={siteId} onAdded={reloadAll} defaultOpen />}
          />
          <SuggestionsPanel
            open={suggestionsOpen}
            onToggle={() => setSuggestionsOpen((v) => !v)}
            loading={suggestionsApi.loading && !suggestionsApi.data}
            error={suggestionsApi.error}
            suggestions={suggestions}
            onAdd={addSuggestions}
          />
        </>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-8">
            {kpiList.map((k) => (
              <KpiCard key={k.label} label={k.label} value={k.value} />
            ))}
          </div>

          <Card className="mt-4" title="Tracked keywords" subtitle="Latest SERP observation per keyword — GSC impressions are a separate signal.">
            <div className="flex flex-col gap-3">
              <div className="flex flex-wrap items-center gap-2">
                <Button variant="ghost" onClick={checkAllNow} disabled={checkingAll} title="Queue a fresh check for every tracked keyword">
                  <RefreshCcw size={14} className={checkingAll ? "animate-spin" : ""} /> Check all now
                </Button>
                <AddKeywordsDialog siteId={siteId} onAdded={reloadAll} />
                <Button variant="ghost" onClick={exportCsv} title="Export latest position per keyword as CSV">
                  <Download size={14} /> Export CSV
                </Button>
              </div>
              {msg && (
                <p role="status" className={`text-xs ${msg.tone === "warn" ? "text-warn" : msg.tone === "bad" ? "text-bad" : "text-muted"}`}>
                  {msg.text}
                </p>
              )}
              {exportError && <p className="text-xs text-bad">{exportError}</p>}
              <ResponsiveTable
                columns={columns}
                rows={rows}
                rowKey={(r) => r.id}
                emptyMessage="No keywords tracked yet — add your first keywords above."
              />
              {moreError && <p className="text-center text-xs text-bad">{moreError}</p>}
              {hasMore && (
                <div className="flex justify-center">
                  <button
                    onClick={loadMore}
                    disabled={loadingMore}
                    className="rounded-xl border border-line bg-card px-4 py-2 text-sm font-semibold text-fg transition hover:border-brand/40 hover:bg-card2 disabled:opacity-50"
                  >
                    {loadingMore ? "Loading…" : "Load more"}
                  </button>
                </div>
              )}
            </div>
          </Card>

          <SuggestionsPanel
            open={suggestionsOpen}
            onToggle={() => setSuggestionsOpen((v) => !v)}
            loading={suggestionsApi.loading && !suggestionsApi.data}
            error={suggestionsApi.error}
            suggestions={suggestions}
            onAdd={addSuggestions}
          />
        </>
      )}
    </div>
  );
}

/**
 * Collapsible card of untracked GSC queries worth tracking (doc 13: user
 * confirmation before the suggestion consumes quota). Adding one row or all
 * rows posts the same confirm endpoint in one batch, then reloads.
 */
function SuggestionsPanel({
  open,
  onToggle,
  loading,
  error,
  suggestions,
  onAdd,
}: {
  open: boolean;
  onToggle: () => void;
  loading: boolean;
  error: string | null;
  suggestions: Suggestion[];
  onAdd: (queries: string[]) => void;
}) {
  return (
    <Card className="mt-4">
      <button type="button" onClick={onToggle} className="flex w-full items-center justify-between gap-3 text-left">
        <span className="text-[15px] font-semibold text-fg">Suggested keywords from Search Console</span>
        <span className="text-xs text-muted">{open ? "Hide" : "Show"}</span>
      </button>
      {open && (
        <div className="mt-4">
          {loading ? (
            <Spinner />
          ) : error ? (
            <p className="text-xs text-warn">{error}</p>
          ) : suggestions.length === 0 ? (
            <p className="py-2 text-center text-sm text-muted">No new keyword suggestions yet — sync Search Console.</p>
          ) : (
            <>
              <ul className="divide-y divide-line">
                {suggestions.map((s) => (
                  <li key={s.query} className="flex items-center gap-3 py-2.5 text-sm">
                    <div className="min-w-0 flex-1">
                      <p className="truncate font-medium text-fg">{s.query}</p>
                      <p className="text-xs text-muted" title="Search Console impressions — separate signal">
                        {num(s.impressions)} impr.
                      </p>
                    </div>
                    <Button variant="ghost" className="px-2.5 py-1 text-xs" onClick={() => onAdd([s.query])} title="Track this keyword">
                      Add
                    </Button>
                  </li>
                ))}
              </ul>
              <div className="mt-3 flex justify-end">
                <Button variant="ghost" onClick={() => onAdd(suggestions.map((s) => s.query))} title="Track every suggested keyword">
                  <ListPlus size={14} /> Add all
                </Button>
              </div>
            </>
          )}
        </div>
      )}
    </Card>
  );
}
