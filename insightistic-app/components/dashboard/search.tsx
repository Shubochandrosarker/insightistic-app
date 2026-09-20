"use client";
import { ReactNode, useEffect, useRef, useState } from "react";
import { Download, RefreshCcw } from "lucide-react";
import { Button } from "@/components/ui/Button";
import { apiGet, apiPost } from "@/lib/api";

/**
 * Shared pieces for the Search Intelligence pages (roadmap doc 10):
 * window tabs, delta/CTR cells, and the export + backfill toolbar. Search
 * windows are independent of the global date range — GSC comparisons are
 * always "last N days vs the previous N days", so these pages never consume
 * `period`/`rangeQuery` from the dashboard context.
 */

export type SearchWindow = 7 | 28 | 90;

/** Segmented 7 / 28 / 90 day chips. GSC windows are fixed comparison windows. */
export function WindowTabs({ value, onChange }: { value: SearchWindow; onChange: (w: SearchWindow) => void }) {
  const tabs: { w: SearchWindow; label: string }[] = [
    { w: 7, label: "7 days" },
    { w: 28, label: "28 days" },
    { w: 90, label: "90 days" },
  ];
  return (
    <div className="flex flex-wrap gap-2">
      {tabs.map((t) => (
        <button
          key={t.w}
          type="button"
          title={`vs previous ${t.w} days`}
          onClick={() => onChange(t.w)}
          className={`rounded-full px-3 py-1.5 text-xs font-semibold transition ${
            value === t.w ? "bg-brand text-white" : "bg-card2 text-muted hover:text-fg"
          }`}
        >
          {t.label}
        </button>
      ))}
    </div>
  );
}

/**
 * Colored +/- delta cell: up is good (green) by default. Pass `invert` for
 * metrics where improvement is negative — e.g. average position moving from
 * 8.4 to 5.1 is a win even though the delta is negative.
 */
export function DeltaCell({ value, unit = "", invert = false }: { value: number | null | undefined; unit?: string; invert?: boolean }) {
  if (value === null || value === undefined) return <span className="text-muted">—</span>;
  const up = value > 0;
  const down = value < 0;
  const good = invert ? down : up;
  const tone = value === 0 ? "text-muted" : good ? "text-good" : "text-bad";
  const shown = Number.isInteger(value) ? String(Math.abs(value)) : Math.abs(value).toFixed(1);
  const arrow = up ? "↑" : down ? "↓" : "";
  return (
    <span className={`text-xs font-semibold ${tone}`}>
      {arrow} {shown}
      {unit}
    </span>
  );
}

/** CTR as a percentage. The search endpoints deliver ctr as a 0–1 ratio. */
export function CtrCell({ value }: { value: number | null }) {
  if (value === null || value === undefined) return <span className="text-muted">—</span>;
  return <span>{(value * 100).toFixed(1)}%</span>;
}

/** Page path in muted mono, middle-truncated when it would wrap the row. */
export function PathCell({ path }: { path: string }) {
  const MAX = 48;
  const half = MAX / 2;
  const shown = path.length > MAX ? `${path.slice(0, Math.ceil(half) - 1)}…${path.slice(-(Math.floor(half) - 1))}` : path;
  return (
    <span className="break-all font-mono text-xs text-muted" title={path}>
      {shown}
    </span>
  );
}

/** Main metric with the previous window's value as a muted "← prev" sub-line. */
export function ValueWithPrev({ main, prev }: { main: ReactNode; prev?: ReactNode | null }) {
  return (
    <div>
      <span className="font-medium">{main}</span>
      {prev != null && prev !== "" ? <span className="block text-xs text-muted">← {prev}</span> : null}
    </div>
  );
}

/** Query-string builder shared by the queries/pages explorers (and their Load more fetches). */
export function searchListParams(opts: {
  window: SearchWindow;
  q?: string;
  minImpressions?: number | null;
  sort?: string;
  dir?: string;
  cursor?: string | null;
}): string {
  const sp = new URLSearchParams();
  sp.set("window", String(opts.window));
  if (opts.q) sp.set("q", opts.q);
  if (opts.minImpressions != null && opts.minImpressions > 0) sp.set("min_impressions", String(opts.minImpressions));
  sp.set("sort", opts.sort || "clicks");
  sp.set("dir", opts.dir || "desc");
  if (opts.cursor) sp.set("cursor", opts.cursor);
  sp.set("per_page", "50");
  return sp.toString();
}

/**
 * Toolbar for the Search Intelligence pages: window tabs, CSV export, and an
 * optional history backfill. The backfill dispatches server-side and then
 * polls backfill-status (3s interval, max 20 tries) while any dimension is
 * pending/running; a 422 gsc_not_connected points at Integrations instead of
 * looking like a random failure.
 */
export function SearchToolbar({
  window,
  onWindowChange,
  onExport,
  exportType,
  showBackfill = false,
  siteId = null,
}: {
  window: SearchWindow;
  onWindowChange: (w: SearchWindow) => void;
  onExport: () => void;
  exportType: string;
  showBackfill?: boolean;
  siteId?: number | null;
}) {
  const [busy, setBusy] = useState(false);
  const [progress, setProgress] = useState<string | null>(null);
  const [hint, setHint] = useState<string | null>(null);
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);

  // NOTE: the `window` prop shadows the global — never touch globals here
  // without globalThis (see the confirm call below).
  function pollStatus(tries: number) {
    if (tries >= 20) {
      if (alive.current) {
        setBusy(false);
        setProgress("Backfill is still running in the background — check back in a few minutes for the latest history.");
      }
      return;
    }
    apiGet(`/sites/${siteId}/search/backfill-status`)
      .then((res: any) => {
        if (!alive.current) return;
        const states: any[] = res?.states || [];
        const active = states.find((s) => s.status === "pending" || s.status === "running");
        if (active) {
          setProgress(`Backfilling ${active.dimension}… covered to ${active.covered_to || "—"}`);
          setTimeout(() => pollStatus(tries + 1), 3000);
        } else {
          setBusy(false);
          setProgress(states.length ? "History backfilled." : null);
        }
      })
      .catch(() => {
        if (alive.current) {
          setBusy(false);
          setProgress(null);
          setHint("Couldn't read backfill status — try again later.");
        }
      });
  }

  function startBackfill() {
    if (!siteId || busy) return;
    if (!globalThis.confirm(`Backfill pulls the last 90 days of Search Console ${exportType === "pages" ? "page" : "query"} history for this site. Continue?`)) return;
    setBusy(true);
    setHint(null);
    apiPost(`/sites/${siteId}/search/backfill`)
      .then((res: any) => {
        if (!alive.current) return;
        const dims: string[] = res?.dimensions || [];
        setProgress(`Backfill dispatched for ${dims.join(", ") || "query data"}…`);
        pollStatus(0);
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setBusy(false);
        if (e?.data?.code === "gsc_not_connected" || e?.status === 422) {
          setHint("Search Console isn't connected for this site — connect it under Integrations to backfill history.");
        } else {
          setHint(e?.message || "Backfill failed. Try again in a moment.");
        }
      });
  }

  return (
    <div className="flex flex-col gap-2">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <WindowTabs value={window} onChange={onWindowChange} />
        <div className="flex flex-wrap items-center gap-2">
          {showBackfill && siteId ? (
            <Button variant="ghost" onClick={startBackfill} disabled={busy} title="Pull missing Search Console history">
              <RefreshCcw size={14} className={busy ? "animate-spin" : ""} /> Backfill history
            </Button>
          ) : null}
          <Button variant="ghost" onClick={onExport} title={`Export ${exportType} as CSV`}>
            <Download size={14} /> Export CSV
          </Button>
        </div>
      </div>
      {progress && (
        <p role="status" className="text-xs text-muted">
          {progress}
        </p>
      )}
      {hint && <p className="text-xs text-warn">{hint}</p>}
    </div>
  );
}
