"use client";
import { useEffect, useRef, useState } from "react";
import { Activity, RefreshCcw } from "lucide-react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { num, timeAgo } from "@/lib/format";

/**
 * Uptime (roadmap doc 16, Phase 12): availability monitoring with confirmed
 * incidents — one failed probe never alerts. The incident lifecycle is
 * healthy -> suspected -> confirmed -> recovered; `confirmed_at` null means
 * SUSPECTED ("waiting for the second probe"), and that distinction is shown
 * verbatim in the incidents table instead of every failed probe reading as
 * an outage.
 *
 * One monitor per site: POST /monitors is create-or-update, so the client
 * never tracks an id. Writes are owner/admin server-side — like the audit
 * RunBar we keep the buttons visible and render a graceful 403 message.
 */

type CheckPayload = {
  status?: string | null;
  status_code?: number | null;
  response_ms?: number | null;
  error?: string | null;
  checked_at?: string | null;
};

type Monitor = {
  id: number | string;
  url?: string | null;
  interval_minutes?: number | null;
  last_checked_at?: string | null;
  last_check?: CheckPayload | null;
  ssl_expires_at?: string | null;
};

type Incident = {
  id: number | string;
  status?: string;
  started_at?: string | null;
  confirmed_at?: string | null;
  recovered_at?: string | null;
  cause?: string | null;
  status_code?: number | null;
  duration_seconds?: number | null;
};

type SslInfo = { expires_at?: string | null; days_left?: number | null; tone?: string | null } | null;

type Msg = { tone: "info" | "warn" | "bad"; text: string };

const INTERVALS = [1, 5, 10, 15, 30];

const inputCls =
  "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none transition placeholder:text-muted focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/15";

/** Human outage length: 42s, 5m, 2h 05m, 3d 4h. */
function formatDuration(seconds: number): string {
  const s = Math.max(0, Math.round(seconds));
  if (s < 60) return `${s}s`;
  const m = Math.floor(s / 60);
  if (m < 60) return `${m}m`;
  const h = Math.floor(m / 60);
  if (h < 24) return `${h}h ${String(m % 60).padStart(2, "0")}m`;
  const d = Math.floor(h / 24);
  return `${d}d ${h % 24}h`;
}

/** Incident duration — computed client-side from the milestones when recovered. */
function incidentDuration(i: Incident): string | null {
  if (i.recovered_at && i.started_at) {
    const ms = new Date(i.recovered_at).getTime() - new Date(i.started_at).getTime();
    if (!Number.isNaN(ms)) return formatDuration(ms / 1000);
  }
  if (i.duration_seconds != null) return formatDuration(Number(i.duration_seconds));
  return null;
}

/** SSL line: expiry date + days left, or "Not checked yet" when no scan ran. */
function sslLabel(ssl: SslInfo): string {
  if (!ssl) return "Not checked yet";
  const d = ssl.days_left;
  if (ssl.tone === "expired" || (d != null && d < 0)) return `Expired${ssl.expires_at ? ` — ${ssl.expires_at}` : ""}`;
  if (d == null) return ssl.expires_at ? `Expires ${ssl.expires_at}` : "Not checked yet";
  return `${d} day${d === 1 ? "" : "s"} left${ssl.expires_at ? ` — ${ssl.expires_at}` : ""}`;
}

function sslToneCls(ssl: SslInfo): string {
  if (!ssl) return "text-muted";
  const tone = ssl.tone ?? (ssl.days_left != null ? (ssl.days_left < 7 ? "critical" : ssl.days_left < 21 ? "warning" : "ok") : null);
  return tone === "ok" ? "text-good" : tone === "warning" ? "text-warn" : tone ? "text-bad" : "text-muted";
}

export default function UptimePage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const up = useApi<any>(siteId ? `/sites/${siteId}/uptime` : null);

  const [msg, setMsg] = useState<Msg | null>(null);
  const [busy, setBusy] = useState(false);
  const [probe, setProbe] = useState<CheckPayload | null>(null);
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);

  const flash = (tone: Msg["tone"], text: string) => setMsg({ tone, text });

  function createMonitor(url: string, interval: number) {
    if (!siteId || busy) return;
    setBusy(true);
    setMsg(null);
    apiPost(`/sites/${siteId}/uptime/monitors`, {
      ...(url.trim() ? { url: url.trim() } : {}),
      interval_minutes: interval,
    })
      .then(() => {
        if (!alive.current) return;
        setBusy(false);
        flash("info", "Monitoring is live — the first probe runs shortly.");
        up.reload();
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setBusy(false);
        if (e?.status === 403) {
          flash("warn", "You don't have permission to configure uptime monitoring — ask a site owner or admin.");
        } else {
          flash("bad", e?.message || "Couldn't create the monitor. Try again in a moment.");
        }
      });
  }

  function checkNow() {
    if (!siteId || busy) return;
    setBusy(true);
    setMsg(null);
    apiPost(`/sites/${siteId}/uptime/check`, {})
      .then((res: any) => {
        if (!alive.current) return;
        setBusy(false);
        setProbe(res?.result ?? null);
        if (res?.incident) flash("warn", "Probe complete — an incident is open.");
        else flash("info", "Probe complete.");
        up.reload();
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setBusy(false);
        if (e?.status === 403) {
          flash("warn", "You don't have permission to run uptime checks — ask a site owner or admin.");
        } else {
          flash("bad", e?.message || "Couldn't run the check. Try again in a moment.");
        }
      });
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<Activity size={20} />}
        title="No website yet"
        hint="Add a website to monitor its availability here — uptime starts automatically."
      />
    );

  if (up.loading && !up.data) return <Spinner />;

  if (up.error)
    return (
      <div>
        <PageHeader
          title="Uptime"
          subtitle={`${site?.name || ""} · Availability monitoring with confirmed incidents — one failed probe never alerts.`}
        />
        <ErrorState message={up.error} onRetry={up.reload} />
      </div>
    );

  const data = up.data || {};
  const monitor: Monitor | null = data.monitor ?? null;
  const hasMonitor = !!data.has_monitor && !!monitor;
  const incidents: Incident[] = data.incidents || [];
  // SSL arrives as its own payload ({expires_at, days_left, tone}); fall back
  // to the monitor's raw expiry so an older payload still renders something.
  const ssl: SslInfo =
    data.ssl ?? (monitor?.ssl_expires_at ? { expires_at: String(monitor.ssl_expires_at).slice(0, 10) } : null);
  const availability = data.availability_pct;

  const incidentColumns: Column<Incident>[] = [
    { key: "started_at", label: "Started", render: (i) => timeAgo(i.started_at ?? null) },
    {
      key: "confirmed_at",
      label: "Confirmed",
      render: (i) =>
        i.confirmed_at ? (
          timeAgo(i.confirmed_at)
        ) : (
          <span title="Waiting for the second probe — one failed probe never alerts.">
            <Badge tone="amber" dot>
              Suspected
            </Badge>
          </span>
        ),
    },
    {
      key: "recovered_at",
      label: "Recovered",
      render: (i) =>
        i.recovered_at ? (
          timeAgo(i.recovered_at)
        ) : (
          <Badge tone="red" dot>
            Ongoing
          </Badge>
        ),
    },
    {
      key: "duration",
      label: "Duration",
      align: "right",
      render: (i) => (i.recovered_at ? incidentDuration(i) ?? "—" : "—"),
    },
    {
      key: "cause",
      label: "Cause",
      render: (i) => (
        <span className="text-sm">
          {i.cause || "—"}
          {i.status_code != null && <span className="ml-2 text-xs text-muted">HTTP {i.status_code}</span>}
        </span>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="Uptime"
        subtitle={`${site?.name || ""} · Availability monitoring with confirmed incidents — one failed probe never alerts.`}
      />

      {msg && (
        <p role="status" className={`mb-4 text-xs ${msg.tone === "warn" ? "text-warn" : msg.tone === "bad" ? "text-bad" : "text-muted"}`}>
          {msg.text}
        </p>
      )}

      {!hasMonitor ? (
        <SetupCard
          siteId={siteId}
          domain={site?.domain}
          busy={busy}
          onCreate={createMonitor}
        />
      ) : (
        <>
          <Card
            title="Monitor"
            right={<SourceFreshness label="Last checked" asOf={monitor!.last_checked_at ?? null} />}
          >
            <div className="flex flex-wrap items-end gap-x-10 gap-y-5">
              <div>
                <p className="text-xs font-medium text-muted">Availability (30d)</p>
                <div className="mt-1 flex items-baseline gap-1">
                  <span
                    className={`text-5xl font-bold tracking-tight ${availability != null ? "text-fg" : "text-muted"}`}
                    title={monitor!.url || undefined}
                  >
                    {availability != null ? `${Math.round(Number(availability))}%` : "—"}
                  </span>
                </div>
              </div>
              <div>
                <p className="text-xs font-medium text-muted">Target</p>
                <p className="mt-1 max-w-xs truncate font-mono text-sm text-fg" title={monitor!.url || undefined}>
                  {monitor!.url || "—"}
                </p>
              </div>
              <div>
                <p className="text-xs font-medium text-muted">Interval</p>
                <p className="mt-1 text-sm font-semibold text-fg">every {num(Number(monitor!.interval_minutes))} min</p>
              </div>
              <div>
                <p className="text-xs font-medium text-muted">SSL certificate</p>
                <p className={`mt-1 text-sm font-semibold ${sslToneCls(ssl)}`}>{sslLabel(ssl)}</p>
              </div>
              <div className="ml-auto">
                <Button variant="ghost" onClick={checkNow} disabled={busy} title="Probe the site right now">
                  <RefreshCcw size={14} className={busy ? "animate-spin" : ""} /> Check now
                </Button>
              </div>
            </div>

            {probe && (
              <div role="status" className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl border border-line bg-card2 px-4 py-3 text-sm">
                <Badge tone={probe.status === "up" ? "green" : "red"} dot>
                  {probe.status === "up" ? "Up" : "Down"}
                </Badge>
                <span className="text-muted">
                  HTTP <span className="font-semibold text-fg">{probe.status_code ?? "—"}</span>
                </span>
                <span className="text-muted">
                  <span className="font-semibold text-fg">{probe.response_ms != null ? num(Number(probe.response_ms)) : "—"}</span> ms
                </span>
                {probe.error && <span className="text-bad">{probe.error}</span>}
                <span className="ml-auto text-xs text-muted">{timeAgo(probe.checked_at ?? null)}</span>
              </div>
            )}
          </Card>

          <Card
            className="mt-4"
            title="Incidents"
            subtitle="Last 20 — confirmed only after a second failed probe; a single blip stays suspected."
          >
            <ResponsiveTable
              columns={incidentColumns}
              rows={incidents}
              rowKey={(i) => i.id}
              emptyMessage="No incidents — all clear."
            />
          </Card>
        </>
      )}
    </div>
  );
}

/** First-run card: pick a target (optional — defaults to the site's domain) and an interval. */
function SetupCard({
  siteId,
  domain,
  busy,
  onCreate,
}: {
  siteId: number;
  domain?: string;
  busy: boolean;
  onCreate: (url: string, interval: number) => void;
}) {
  const [url, setUrl] = useState("");
  const [interval, setInterval] = useState(5);

  return (
    <Card title="Set up uptime monitoring" className="max-w-xl">
      <p className="text-sm text-muted">
        We probe your site on a schedule and confirm every incident with a second probe — a single failed request never
        alerts.
      </p>
      <div className="mt-4 space-y-3">
        <div>
          <label htmlFor="uptime-url" className="mb-1 block text-xs font-semibold text-fg">
            Target URL <span className="font-normal text-muted">(optional)</span>
          </label>
          <input
            id="uptime-url"
            aria-label="Target URL"
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            placeholder="https://domain"
            className={`${inputCls} w-full`}
          />
          <p className="mt-1 text-xs text-muted">
            Leave empty to monitor {domain ? `https://${domain}` : "your site's domain"}.
          </p>
        </div>
        <div>
          <label htmlFor="uptime-interval" className="mb-1 block text-xs font-semibold text-fg">
            Check interval
          </label>
          <select
            id="uptime-interval"
            aria-label="Check interval"
            value={interval}
            onChange={(e) => setInterval(Number(e.target.value))}
            className={inputCls}
          >
            {INTERVALS.map((m) => (
              <option key={m} value={m}>
                Every {m} minute{m === 1 ? "" : "s"}
              </option>
            ))}
          </select>
          <p className="mt-1 text-xs text-muted">Higher frequencies are plan-limited — the server clamps up to your plan.</p>
        </div>
        <Button onClick={() => onCreate(url, interval)} disabled={busy}>
          {busy ? "Creating…" : "Create monitor"}
        </Button>
      </div>
    </Card>
  );
}
