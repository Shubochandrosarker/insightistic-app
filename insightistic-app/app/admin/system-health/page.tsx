"use client";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";

/**
 * System health (super admin). 2026-10-13 incident pin: the API 500s here
 * used to fall into `loading || !data` and render the skeleton forever —
 * an admin page must NEVER look like an infinite "Loading" when the call
 * failed. Errors now render as an explicit error state with a retry.
 */
function Health({ title, ok, detail, warn = false }: { title: string; ok: boolean; detail: string; warn?: boolean }) {
  return (
    <Card>
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-fg">{title}</h3>
        <Badge tone={ok ? "green" : warn ? "amber" : "red"} dot>{ok ? "Healthy" : warn ? "Watch" : "Down"}</Badge>
      </div>
      <p className="mt-2 text-sm text-muted">{detail}</p>
    </Card>
  );
}

export default function AdminSystemHealth() {
  const { data, loading, error, reload } = useApi<any>("/admin/system-health");

  if (loading && !data) {
    return (<div><PageHeader title="System Health" subtitle="Platform infrastructure status." /><Spinner /></div>);
  }

  if (error && !data) {
    return (
      <div>
        <PageHeader title="System Health" subtitle="Platform infrastructure status." />
        <Card>
          <div className="flex flex-col items-center gap-3 py-8 text-center">
            <Badge tone="red" dot>API error</Badge>
            <p className="max-w-md text-sm text-muted">{error}</p>
            <p className="text-xs text-muted">
              403 = your account is not a platform super admin. Anything else: retry, or check the API container logs.
            </p>
            <Button variant="ghost" onClick={reload}>Retry</Button>
          </div>
        </Card>
      </div>
    );
  }

  return (
    <div>
      <PageHeader title="System Health" subtitle="Platform infrastructure status." />

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Health title="API" ok={data.api?.status === "ok"} detail={data.api?.url || "—"} />
        <Health title="App" ok={data.app?.status === "ok"} detail={`${data.app?.env} · ${data.app?.url}`} />
        <Health title="Release" ok={data.release?.sha && data.release.sha !== "unknown"} warn={!data.release?.sha || data.release.sha === "unknown"} detail={`${data.release?.sha || "unknown"}${data.release?.built_at ? ` · built ${data.release.built_at}` : ""}`} />
        <Health title="Database" ok={data.database?.status === "ok"} detail={`Driver: ${data.database?.driver}`} />
        <Health title="Cache" ok={data.cache?.ok !== false} warn={data.cache?.ok === false} detail={`Driver: ${data.cache?.driver || "—"}${data.cache?.ok === false ? " · write/read failed" : ""}`} />
        <Health title="Queue" ok={(data.queue?.failed ?? 0) === 0} warn={(data.queue?.failed ?? 0) > 0} detail={`${data.queue?.pending ?? 0} pending · ${data.queue?.failed ?? 0} failed · ${data.queue?.connection}`} />
        <Health title="Scheduler" ok detail={data.scheduler?.detail || "configured"} />
        <Health title="Storage" ok={!!data.storage?.writable} detail={`${data.storage?.writable ? "writable" : "not writable"} · disk: ${data.storage?.reports_disk}`} />
        <Health title="Mail" ok={!!data.mail?.configured} warn={!data.mail?.configured} detail={`Mailer: ${data.mail?.mailer}`} />
        {(["paddle", "stripe"] as const).map((id) => {
          const p = data[id] || {};
          const label = id === "paddle" ? "Paddle" : "Stripe";
          const ok = !!p.configured && p.api === "ok" && !!p.webhook_signing_configured;
          const parts = [
            p.configured ? (p.mode || p.environment || "configured") : "not configured",
            p.api && p.api !== "not_configured" ? `API ${p.api}${p.api_error ? ` (${p.api_error})` : ""}` : null,
            p.configured ? `webhook ${p.webhook_signing_configured ? "signed" : "secret missing"}` : null,
            p.last_webhook_at ? `last event ${new Date(p.last_webhook_at).toLocaleString()}${p.webhook_recent ? "" : " (stale)"}` : p.configured ? "no events yet" : null,
            p.offered_at_checkout === false ? "hidden at checkout" : null,
          ].filter(Boolean);
          return <Health key={id} title={label} ok={ok} warn={!ok} detail={parts.join(" · ")} />;
        })}
        <Health title="AI provider" ok={!!data.ai?.configured && data.ai?.reachable !== false} warn={!data.ai?.configured || data.ai?.reachable === false} detail={`Provider: ${data.ai?.provider}${data.ai?.reachable === false ? " · unreachable (check API key/quota)" : data.ai?.reachable === true ? " · reachable" : ""}`} />
      </div>

      <Card className="mt-5" title="Recent failed jobs">
        {(data.recent_failed_jobs || []).length === 0 ? (
          <p className="py-6 text-center text-sm text-muted">No failed jobs 🎉</p>
        ) : (
          <div className="space-y-2">
            {data.recent_failed_jobs.map((j: any) => (
              <div key={j.id} className="rounded-xl border border-line bg-card2 p-3">
                <div className="flex items-center justify-between text-xs">
                  <span className="font-semibold text-fg">queue: {j.queue}</span>
                  <span className="text-muted">{j.failed_at}</span>
                </div>
                <p className="mt-1 font-mono text-[11px] text-bad">{j.error}</p>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  );
}
