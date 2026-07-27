"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Radar } from "lucide-react";

/**
 * Platform observability (spec Phase 2.3).
 *
 * Deliberately reports what is *configured* rather than what is possible: an
 * exporter with no endpoint and one that is exporting look identical on a
 * dashboard that only lists features, and the difference is the whole point.
 */

const WINDOWS = [
  { hours: 1, label: "1h" },
  { hours: 24, label: "24h" },
  { hours: 168, label: "7d" },
  { hours: 720, label: "30d" },
] as const;

const QUEUE_TONE: Record<string, "red" | "amber" | "green" | "slate"> = {
  critical: "red", warning: "amber", ok: "green", unknown: "slate",
};

function num(v: number | null | undefined) {
  return v == null ? "—" : v.toLocaleString();
}

function ms(v: number | null | undefined) {
  if (v == null) return "—";
  return v >= 1000 ? `${(v / 1000).toFixed(2)}s` : `${v}ms`;
}

function usd(v: number | null | undefined) {
  if (v == null) return "—";
  // Sub-cent AI costs are the norm; 2dp would report every call as free.
  return v < 0.01 && v > 0 ? `$${v.toFixed(6)}` : `$${v.toFixed(2)}`;
}

function duration(seconds: number | null | undefined) {
  if (seconds == null) return "—";
  if (seconds < 60) return `${seconds}s`;
  if (seconds < 3600) return `${Math.round(seconds / 60)}m`;
  return `${(seconds / 3600).toFixed(1)}h`;
}

function Stat({ label, value, hint }: { label: string; value: React.ReactNode; hint?: string }) {
  return (
    <div className="rounded-xl border border-line bg-card2 p-3">
      <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{label}</div>
      <div className="mt-1 text-lg font-bold text-fg">{value}</div>
      {hint && <div className="mt-0.5 text-xs text-muted">{hint}</div>}
    </div>
  );
}

function Th({ children, right }: { children: React.ReactNode; right?: boolean }) {
  return (
    <th className={`pb-2 text-[11px] font-semibold uppercase tracking-wide text-muted ${right ? "text-right" : "text-left"}`}>
      {children}
    </th>
  );
}

function Td({ children, right }: { children: React.ReactNode; right?: boolean }) {
  return <td className={`py-2 text-sm text-fg ${right ? "text-right tabular-nums" : ""}`}>{children}</td>;
}

export default function ObservabilityPage() {
  const [hours, setHours] = useState<number>(24);
  const { data, loading, error, reload } = useApi<any>(`/admin/observability?hours=${hours}`, [hours]);

  return (
    <div>
      <PageHeader
        title="Observability"
        subtitle="Request latency, AI spend, queue health and which telemetry exporters are actually wired up."
        right={
          <div className="flex items-center gap-2">
            <div className="inline-flex rounded-xl border border-line bg-card p-1">
              {WINDOWS.map((w) => (
                <button
                  key={w.hours}
                  onClick={() => setHours(w.hours)}
                  className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                    hours === w.hours ? "bg-brand text-white" : "text-muted hover:text-fg"
                  }`}
                >
                  {w.label}
                </button>
              ))}
            </div>
            <button
              onClick={reload}
              className="rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-fg hover:opacity-80"
            >
              Refresh
            </button>
          </div>
        }
      />

      {error && <p className="mb-4 text-sm text-bad">{error}</p>}

      {loading ? (
        <Spinner />
      ) : !data ? null : (
        <div className="space-y-4">
          <Card>
            <h2 className="mb-3 text-sm font-semibold text-fg">Queue</h2>
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <Badge tone={QUEUE_TONE[data.queue.status.level] || "slate"} dot>
                {data.queue.status.level}
              </Badge>
              <span className="text-sm text-muted">{data.queue.status.detail}</span>
            </div>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <Stat label="Driver" value={data.queue.driver} />
              <Stat label="Pending" value={num(data.queue.pending)} />
              <Stat
                label="Oldest waiting"
                value={duration(data.queue.oldest_pending_age_seconds)}
                hint="Depth alone can't tell a fast queue from a stalled one."
              />
              <Stat
                label="Worker last seen"
                value={duration(data.queue.worker.age_seconds)}
                hint={data.queue.worker.stale ? "Stale" : "Live"}
              />
            </div>

            {data.queue.failed.recent.length > 0 && (
              <div className="mt-4">
                <p className="mb-2 text-xs text-muted">
                  {num(data.queue.failed.total)} failed jobs total · {num(data.queue.failed.last_24h)} in the last 24h
                </p>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[520px]">
                    <thead className="border-b border-line">
                      <tr>
                        <Th>Job</Th>
                        <Th>Error</Th>
                        <Th right>Failed</Th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {data.queue.failed.recent.map((f: any) => (
                        <tr key={f.id}>
                          <Td>{f.job || "—"}</Td>
                          <Td>
                            <span className="text-xs text-muted">{f.error}</span>
                          </Td>
                          <Td right>{f.failed_at}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}
          </Card>

          <Card>
            <h2 className="mb-3 text-sm font-semibold text-fg">Requests</h2>
            {!data.requests.has_data ? (
              <p className="text-sm text-muted">No request telemetry recorded in this window yet.</p>
            ) : (
              <>
                <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <Stat label="Requests" value={num(data.requests.totals.requests)} />
                  <Stat label="5xx" value={num(data.requests.totals.server_errors)} />
                  <Stat label="4xx" value={num(data.requests.totals.client_errors)} />
                  <Stat label="Error rate" value={data.requests.totals.error_rate == null ? "—" : `${data.requests.totals.error_rate}%`} />
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[600px]">
                    <thead className="border-b border-line">
                      <tr>
                        <Th>Route</Th>
                        <Th right>Calls</Th>
                        <Th right>Avg</Th>
                        <Th right>Max</Th>
                        <Th right>&gt;1s</Th>
                        <Th right>5xx</Th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {data.requests.routes.map((r: any, i: number) => (
                        <tr key={i}>
                          <Td>
                            <span className="font-mono text-xs">{r.method}</span> {r.route}
                          </Td>
                          <Td right>{num(r.requests)}</Td>
                          <Td right>{ms(r.avg_ms)}</Td>
                          <Td right>{ms(r.max_ms)}</Td>
                          <Td right>{num(r.over_1s)}</Td>
                          <Td right>
                            <span className={r.server_errors > 0 ? "text-bad" : ""}>{num(r.server_errors)}</span>
                          </Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </Card>

          <Card>
            <h2 className="mb-3 text-sm font-semibold text-fg">AI spend</h2>
            {!data.ai.has_data ? (
              <p className="text-sm text-muted">No AI calls recorded in this window yet.</p>
            ) : (
              <>
                <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <Stat label="Calls" value={num(data.ai.totals.calls)} />
                  <Stat label="Failures" value={num(data.ai.totals.failures)} />
                  <Stat label="Tokens in / out" value={`${num(data.ai.totals.tokens_in)} / ${num(data.ai.totals.tokens_out)}`} />
                  <Stat label="Cost" value={usd(data.ai.totals.cost_usd)} />
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[600px]">
                    <thead className="border-b border-line">
                      <tr>
                        <Th>Kind</Th>
                        <Th>Model</Th>
                        <Th right>Calls</Th>
                        <Th right>Fail %</Th>
                        <Th right>Avg</Th>
                        <Th right>Cost</Th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-line">
                      {data.ai.breakdown.map((r: any, i: number) => (
                        <tr key={i}>
                          <Td>{r.kind}</Td>
                          <Td>
                            <span className="text-xs text-muted">{r.provider}</span> {r.model}
                          </Td>
                          <Td right>{num(r.calls)}</Td>
                          <Td right>{r.failure_rate == null ? "—" : `${r.failure_rate}%`}</Td>
                          <Td right>{ms(r.avg_ms)}</Td>
                          <Td right>{usd(r.cost_usd)}</Td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </>
            )}
          </Card>

          <Card>
            <h2 className="mb-1 text-sm font-semibold text-fg">Exporters</h2>
            <p className="mb-3 text-xs text-muted">
              Whether each sink is actually wired up. Credentials are never returned by the API.
            </p>
            <div className="grid gap-3 sm:grid-cols-3">
              <div className="rounded-xl border border-line bg-card2 p-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-semibold text-fg">Errors</span>
                  <Badge tone={data.exporters.errors.configured ? "green" : "slate"} dot>
                    {data.exporters.errors.configured ? "configured" : "off"}
                  </Badge>
                </div>
                <div className="mt-2 space-y-0.5 text-xs text-muted">
                  <div>{data.exporters.errors.driver}</div>
                  <div>env: {data.exporters.errors.environment || "—"}</div>
                  <div>release: {data.exporters.errors.release || "—"}</div>
                </div>
              </div>

              <div className="rounded-xl border border-line bg-card2 p-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-semibold text-fg">Tracing</span>
                  <Badge tone={data.exporters.tracing.exporting ? "green" : "slate"} dot>
                    {data.exporters.tracing.exporting ? "exporting" : data.exporters.tracing.enabled ? "no endpoint" : "off"}
                  </Badge>
                </div>
                <div className="mt-2 space-y-0.5 text-xs text-muted">
                  <div>{data.exporters.tracing.driver}</div>
                  <div>collector: {data.exporters.tracing.endpoint_host || "—"}</div>
                  <div>sample: {(data.exporters.tracing.sample_rate * 100).toFixed(0)}%</div>
                </div>
              </div>

              <div className="rounded-xl border border-line bg-card2 p-3">
                <div className="flex items-center justify-between gap-2">
                  <span className="text-sm font-semibold text-fg">Metrics</span>
                  <Badge tone={data.exporters.metrics.enabled ? "green" : "slate"} dot>
                    {data.exporters.metrics.enabled ? "on" : "off"}
                  </Badge>
                </div>
                <div className="mt-2 space-y-0.5 text-xs text-muted">
                  <div>retained {data.exporters.metrics.retention_days} days</div>
                  <div>{num(data.exporters.metrics.buckets.requests)} request buckets</div>
                  <div>{num(data.exporters.metrics.buckets.ai)} AI buckets</div>
                </div>
              </div>
            </div>
          </Card>

          {data.requests.has_data === false && data.ai.has_data === false && (
            <p className="flex items-center gap-2 text-sm text-muted">
              <Radar size={14} /> Telemetry starts accumulating as soon as requests come in.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
