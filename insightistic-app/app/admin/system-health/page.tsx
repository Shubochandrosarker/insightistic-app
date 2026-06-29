"use client";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";

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
  const { data, loading } = useApi<any>("/admin/system-health");

  if (loading || !data) {
    return (<div><PageHeader title="System Health" subtitle="Platform infrastructure status." /><Spinner /></div>);
  }

  return (
    <div>
      <PageHeader title="System Health" subtitle="Platform infrastructure status." />

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Health title="API" ok={data.api?.status === "ok"} detail={data.api?.url || "—"} />
        <Health title="App" ok={data.app?.status === "ok"} detail={`${data.app?.env} · ${data.app?.url}`} />
        <Health title="Database" ok={data.database?.status === "ok"} detail={`Driver: ${data.database?.driver}`} />
        <Health title="Queue" ok={(data.queue?.failed ?? 0) === 0} warn={(data.queue?.failed ?? 0) > 0} detail={`${data.queue?.pending ?? 0} pending · ${data.queue?.failed ?? 0} failed · ${data.queue?.connection}`} />
        <Health title="Scheduler" ok detail={data.scheduler?.detail || "configured"} />
        <Health title="Storage" ok={!!data.storage?.writable} detail={`${data.storage?.writable ? "writable" : "not writable"} · disk: ${data.storage?.reports_disk}`} />
        <Health title="Mail" ok={!!data.mail?.configured} warn={!data.mail?.configured} detail={`Mailer: ${data.mail?.mailer}`} />
        <Health title="Stripe" ok={!!data.stripe?.configured} warn={!data.stripe?.configured} detail={`${data.stripe?.configured ? "configured" : "not configured"} · ${data.stripe?.mode} mode`} />
        <Health title="AI provider" ok={!!data.ai?.configured} warn={!data.ai?.configured} detail={`Provider: ${data.ai?.provider}`} />
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
