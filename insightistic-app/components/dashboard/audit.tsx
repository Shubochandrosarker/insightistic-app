"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import { Play } from "lucide-react";
import { apiGet, apiPost } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { SourceFreshness } from "@/components/dashboard/states";
import { num } from "@/lib/format";

/**
 * Shared pieces for the Site Audit pages (roadmap doc 12): the latest-run
 * hook, the run/refresh bar with crawl dispatch + polling, severity/status
 * badges, and human labels for the crawler's issue codes. Cursor pagination
 * and the states (loading/error/empty) come from the same idioms as the
 * Search Intelligence pages.
 */

export type CrawlRunStatus = "pending" | "running" | "completed" | "failed" | "cancelled";

export type CrawlRun = {
  id: number | string;
  status: CrawlRunStatus;
  trigger: "manual" | "schedule";
  started_at: string | null;
  completed_at: string | null;
  max_pages: number | null;
  pages_discovered: number | null;
  pages_crawled: number | null;
  issues_found: number | null;
  error_summary?: string | null;
};

export type CrawlIssueSeverity = "critical" | "high" | "medium" | "low";

export type CrawlIssue = {
  id: number | string;
  issue_code: string;
  severity: CrawlIssueSeverity;
  category: string;
  target_url: string;
  evidence_json?: any;
  first_seen_at: string | null;
  last_seen_at: string | null;
};

export type ListMeta = { next_cursor: string | null; has_more: boolean; total?: number };

/** Human labels for the crawler's issue codes; unknown codes pass through raw. */
const ISSUE_CODE_LABELS: Record<string, string> = {
  http_error: "HTTP error",
  robots_noindex: "Noindex (informational)",
  missing_title: "Missing title",
  missing_meta_description: "Missing meta description",
  multiple_h1: "Multiple H1s",
  thin_content: "Thin content",
  canonical_missing: "Missing canonical",
  schema_parse_error: "Structured data error",
  image_missing_alt: "Images missing alt",
  broken_internal_link: "Broken internal link",
  redirecting_internal_link: "Redirecting internal link",
};

export function issueCodeLabel(code: string): string {
  return ISSUE_CODE_LABELS[code] ?? code;
}

/** The path (plus query) half of an absolute crawled URL, for the muted mono cell. */
export function urlPath(url: string): string {
  try {
    const u = new URL(url);
    return `${u.pathname || "/"}${u.search || ""}`;
  } catch {
    return url;
  }
}

/** Query-string builder shared by the pages/links/issues explorers (and their Load more fetches). */
export function crawlListParams(opts: {
  runId?: number | string | null;
  extra?: Record<string, string | number | undefined | null>;
  cursor?: string | null;
}): string {
  const sp = new URLSearchParams();
  if (opts.runId != null) sp.set("run_id", String(opts.runId));
  for (const [k, v] of Object.entries(opts.extra || {})) {
    if (v !== undefined && v !== null && v !== "") sp.set(k, String(v));
  }
  sp.set("per_page", "50");
  if (opts.cursor) sp.set("cursor", String(opts.cursor));
  return sp.toString();
}

/** The API's runs endpoint returns `{ runs: [...] }`; keep tolerating the
 * array/data envelopes used by older deployments and the test helpers. */
export function crawlRunsFromResponse(res: unknown): CrawlRun[] {
  if (Array.isArray(res)) return res as CrawlRun[];
  if (!res || typeof res !== "object") return [];

  const payload = res as { runs?: unknown; data?: unknown };
  if (Array.isArray(payload.runs)) return payload.runs as CrawlRun[];
  if (Array.isArray(payload.data)) return payload.data as CrawlRun[];
  return [];
}

/**
 * The crawl runs feed (last 20) plus the newest run as a convenience. Every
 * audit page starts here: no runs at all means the not-crawled-yet state
 * ("Run your first crawl"), the first run id scopes the explorers.
 */
export function useLatestRun(siteId: number | null) {
  const [runs, setRuns] = useState<CrawlRun[]>([]);
  const [loading, setLoading] = useState(!!siteId);
  const [error, setError] = useState<string | null>(null);

  const reload = useCallback(() => {
    if (!siteId) return;
    setLoading(true);
    setError(null);
    apiGet(`/sites/${siteId}/crawl/runs`)
      .then((res: any) => setRuns(crawlRunsFromResponse(res)))
      .catch((e: any) => setError(e?.message || "Couldn't load crawl runs."))
      .finally(() => setLoading(false));
  }, [siteId]);

  useEffect(() => {
    reload();
  }, [reload]);

  return { latestRun: runs.length ? runs[0] : null, runs, loading, error, reload };
}

/** Run status chip: completed green, running amber + pulse, failed red, queued/cancelled neutral. */
export function StatusChip({ status }: { status: string }) {
  const tone = status === "completed" ? "green" : status === "running" ? "amber" : status === "failed" ? "red" : "slate";
  const label = status === "pending" ? "Queued" : status.charAt(0).toUpperCase() + status.slice(1);
  return (
    <Badge tone={tone as any} dot className={status === "running" ? "animate-pulse" : ""}>
      {label}
    </Badge>
  );
}

/** Issue severity chip: critical/high red, medium amber, low neutral. */
export function SeverityBadge({ severity }: { severity: string }) {
  const tone = severity === "critical" || severity === "high" ? "red" : severity === "medium" ? "amber" : "slate";
  return (
    <Badge tone={tone as any} dot={severity === "critical"}>
      {severity.charAt(0).toUpperCase() + severity.slice(1)}
    </Badge>
  );
}

/**
 * The crawl bar shared by every audit page: the latest run's status and
 * counters, plus "Run crawl now" (owner/admin server-side — analysts get a
 * graceful 403 message instead of a hidden button, matching how the rest of
 * the dashboard treats role-gated writes). A dispatched crawl is polled every
 * 5s (max 60 tries) while pending/running; 409 and the monthly-quota 422 get
 * dedicated copy rather than a generic failure.
 */
export function RunBar({
  siteId,
  run,
  onSettled,
  compact = false,
}: {
  siteId: number | null;
  run: CrawlRun | null;
  /** Called when a dispatched crawl settles (or polling gives up) — reload your page data. */
  onSettled?: () => void;
  compact?: boolean;
}) {
  const [starting, setStarting] = useState(false);
  const [polling, setPolling] = useState(false);
  const [activeRun, setActiveRun] = useState<CrawlRun | null>(null);
  const [msg, setMsg] = useState<{ text: string; tone: "info" | "warn" | "bad" } | null>(null);
  const alive = useRef(true);
  const observedRunId = useRef<string | null>(null);
  const onSettledRef = useRef(onSettled);
  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);
  useEffect(() => {
    onSettledRef.current = onSettled;
  }, [onSettled]);

  const busy = starting || polling;
  const displayRun = activeRun && (polling || !run || String(activeRun.id) === String(run.id)) ? activeRun : run;
  const liveRun = displayRun?.status === "pending" || displayRun?.status === "running";

  const poll = useCallback((runId: number | string, tries: number) => {
    if (!alive.current) return;
    if (tries >= 60) {
      setPolling(false);
      setMsg({ text: "The crawl is still running in the background — check back in a few minutes for the results.", tone: "info" });
      onSettledRef.current?.();
      return;
    }
    apiGet(`/sites/${siteId}/crawl/runs`)
      .then((res: any) => {
        if (!alive.current) return;
        const list = crawlRunsFromResponse(res);
        const current = list.find((candidate) => String(candidate.id) === String(runId));

        // A worker can take a moment to publish its run state. Keep watching
        // the requested run instead of treating an empty list as completion.
        if (!current) {
          setTimeout(() => poll(runId, tries + 1), 5000);
          return;
        }

        setActiveRun(current);
        if (current.status === "pending" || current.status === "running") {
          setTimeout(() => poll(runId, tries + 1), 5000);
          return;
        }

        setPolling(false);
        if (current.status === "failed") {
          setMsg({ text: `The last crawl failed${current.error_summary ? `: ${current.error_summary}` : "."}`, tone: "bad" });
        } else if (current.status === "cancelled") {
          setMsg({ text: "The crawl was cancelled.", tone: "warn" });
        } else {
          setMsg({ text: "Crawl finished.", tone: "info" });
        }
        onSettledRef.current?.();
      })
      .catch(() => {
        if (!alive.current) return;
        setPolling(false);
        setMsg({ text: "Lost track of the crawl status — try running again or reloading.", tone: "warn" });
        onSettledRef.current?.();
      });
  }, [siteId]);

  useEffect(() => {
    if (!siteId || !run || (run.status !== "pending" && run.status !== "running")) return;
    const runId = String(run.id);
    if (observedRunId.current === runId) return;

    observedRunId.current = runId;
    setActiveRun(run);
    setPolling(true);
    const timer = window.setTimeout(() => poll(run.id, 0), 3000);
    return () => window.clearTimeout(timer);
  }, [siteId, run, poll]);

  function startRun() {
    if (!siteId || busy) return;
    setActiveRun(null);
    observedRunId.current = null;
    setStarting(true);
    setMsg(null);
    apiPost(`/sites/${siteId}/crawl/run`, {})
      .then((response: any) => {
        if (!alive.current) return;
        const runId = response?.run_id;
        if (runId === undefined || runId === null) {
          throw new Error("The server did not return the crawl run ID.");
        }
        observedRunId.current = String(runId);
        setActiveRun({
          id: runId,
          status: "pending",
          trigger: "manual",
          started_at: null,
          completed_at: null,
          max_pages: response?.max_pages ?? null,
          pages_discovered: 0,
          pages_crawled: 0,
          issues_found: 0,
        });
        setStarting(false);
        setPolling(true);
        setMsg({ text: "Crawl dispatched — watching progress…", tone: "info" });
        // Give the worker a beat before the first status check.
        setTimeout(() => poll(runId, 0), 3000);
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setStarting(false);
        if (e?.status === 409 || e?.data?.code === "crawl_already_running") {
          setMsg({ text: "A crawl is already running.", tone: "warn" });
        } else if (e?.data?.code === "crawl_limit_reached") {
          setMsg({
            text: `Monthly crawl limit reached — ${num(e?.data?.used)} of ${num(e?.data?.limit)} pages used this month. Upgrade your plan for a higher quota.`,
            tone: "warn",
          });
        } else if (e?.status === 403) {
          setMsg({ text: "You don't have permission to start a crawl — ask a site owner or admin.", tone: "warn" });
        } else {
          setMsg({ text: e?.message || "Couldn't start the crawl. Try again in a moment.", tone: "bad" });
        }
      });
  }

  return (
    <div className="flex flex-col gap-2">
      <div
        className={`flex flex-wrap items-center gap-x-4 gap-y-2 ${compact ? "text-xs" : "text-sm"}`}
        data-testid="run-bar"
      >
        {displayRun ? (
          <StatusChip status={displayRun.status} />
        ) : (
          <Badge tone="slate" dot>
            Never crawled
          </Badge>
        )}
        {displayRun && (
          <>
            <span className="text-muted">
              Crawled <span className="font-semibold text-fg">{num(displayRun.pages_crawled ?? undefined)}</span>
              {displayRun.max_pages ? ` / ${num(displayRun.max_pages)}` : ""}
            </span>
            {!compact && (
              <span className="text-muted">
                Discovered <span className="font-semibold text-fg">{num(displayRun.pages_discovered ?? undefined)}</span>
              </span>
            )}
            <span className="text-muted">
              Issues <span className="font-semibold text-fg">{num(displayRun.issues_found ?? undefined)}</span>
            </span>
          </>
        )}
        <SourceFreshness label="Last run" asOf={displayRun?.started_at ?? null} />
        {!compact && liveRun && <span className="text-xs text-warn">Results update as the crawl progresses.</span>}
        <Button variant="ghost" onClick={startRun} disabled={busy || liveRun} className={compact ? "px-3 py-1.5 text-xs" : ""} title="Crawl this site now">
          <Play size={14} /> {displayRun ? "Run crawl now" : "Run first crawl"}
        </Button>
      </div>
      {msg && (
        <p role="status" className={`text-xs ${msg.tone === "warn" ? "text-warn" : msg.tone === "bad" ? "text-bad" : "text-muted"}`}>
          {msg.text}
        </p>
      )}
    </div>
  );
}
