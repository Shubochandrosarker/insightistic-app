"use client";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { num } from "@/lib/format";

/**
 * Platform settings (super admin, read-mostly).
 *
 * 2026-10-13 incident pin: this page used to fetch /admin/system-health
 * (copy-paste) and there was no GET /admin/settings at all. It now reads
 * the dedicated endpoint, which returns true platform facts — counts,
 * release, service status, Paddle env mode — and NEVER secret values.
 * Errors render explicitly (retry), never an infinite skeleton.
 */
function Row({ label, value }: { label: string; value: React.ReactNode }) {
  return (
    <div className="flex items-center justify-between border-b border-line py-3 last:border-0">
      <span className="text-sm text-muted">{label}</span>
      <span className="text-sm font-medium text-fg">{value}</span>
    </div>
  );
}

const MARKETING_URL = process.env.NEXT_PUBLIC_MARKETING_URL || "https://insightistic.com";

export default function AdminSettings() {
  const { data, loading, error, reload } = useApi<any>("/admin/settings");

  if (loading && !data) {
    return (<div><PageHeader title="Platform settings" subtitle="Read-only owner configuration. No secrets are shown." /><Spinner /></div>);
  }

  if (error && !data) {
    return (
      <div>
        <PageHeader title="Platform settings" subtitle="Read-only owner configuration. No secrets are shown." />
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
      <PageHeader title="Platform settings" subtitle="Read-only owner configuration. No secrets are shown." />

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Platform">
          <Row label="Environment" value={<Badge tone={data.app?.env === "production" ? "green" : "amber"}>{data.app?.env}</Badge>} />
          <Row label="Debug mode" value={data.app?.debug ? <Badge tone="red">on</Badge> : <Badge tone="green">off</Badge>} />
          <Row label="Release" value={<span className="font-mono text-xs">{data.release?.sha || "unknown"}</span>} />
          <Row label="Built at" value={data.release?.built_at || "—"} />
          <Row label="App URL" value={data.app?.url} />
          <Row label="API URL" value={<span className="text-muted">{MARKETING_URL === data.app?.url ? "—" : data.app?.url}</span>} />
          <Row label="Marketing URL" value={MARKETING_URL} />
        </Card>

        <Card title="At a glance">
          <Row label="Organizations" value={num(data.counts?.organizations)} />
          <Row label="Users" value={num(data.counts?.users)} />
          <Row label="Sites" value={num(data.counts?.sites)} />
          <Row label="Plans" value={num(data.counts?.plans)} />
          <Row label="Super admins" value={num(data.counts?.super_admins)} />
        </Card>

        <Card title="Services">
          <Row label="Database" value={<Badge tone={data.database?.status === "ok" ? "green" : "red"} dot>{data.database?.driver}</Badge>} />
          <Row label="Cache" value={<Badge tone={data.cache?.ok ? "green" : "red"} dot>{data.cache?.driver}</Badge>} />
          <Row label="Queue" value={`${data.queue?.pending ?? 0} pending · ${data.queue?.failed ?? 0} failed`} />
          <Row label="Scheduler" value={data.scheduler?.detail || "configured"} />
          <Row label="Storage" value={data.storage?.writable ? <Badge tone="green">writable</Badge> : <Badge tone="red">not writable</Badge>} />
          <Row label="Reports disk" value={data.storage?.reports_disk} />
        </Card>

        <Card title="Integrations (status only — no secrets)">
          <Row label="Mail" value={<Badge tone={data.mail?.configured ? "green" : "amber"}>{data.mail?.configured ? "configured" : "not set"}</Badge>} />
          <Row label="Paddle" value={<Badge tone={data.paddle?.configured ? "green" : "amber"}>{data.paddle?.configured ? data.paddle?.environment : "not set"}</Badge>} />
          <Row label="Stripe" value={<Badge tone={data.stripe?.configured ? "green" : "amber"}>{data.stripe?.configured ? data.stripe?.mode : "not set"}</Badge>} />
          <Row label="AI provider" value={<Badge tone={data.ai?.configured ? "green" : "amber"}>{data.ai?.configured ? data.ai?.provider : "not set"}</Badge>} />
        </Card>
      </div>
    </div>
  );
}
