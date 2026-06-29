"use client";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";

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
  const { data, loading } = useApi<any>("/admin/system-health");

  if (loading || !data) {
    return (<div><PageHeader title="Platform settings" subtitle="Read-only owner configuration. No secrets are shown." /><Spinner /></div>);
  }

  return (
    <div>
      <PageHeader title="Platform settings" subtitle="Read-only owner configuration. No secrets are shown." />

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="URLs">
          <Row label="App URL" value={data.app?.url} />
          <Row label="API URL" value={data.api?.url} />
          <Row label="Marketing URL" value={MARKETING_URL} />
        </Card>

        <Card title="Environment">
          <Row label="Environment" value={<Badge tone={data.app?.env === "production" ? "green" : "amber"}>{data.app?.env}</Badge>} />
          <Row label="Debug" value={data.app?.debug ? <Badge tone="red">on</Badge> : <Badge tone="green">off</Badge>} />
          <Row label="Database" value={data.database?.driver} />
          <Row label="Queue" value={data.queue?.connection} />
        </Card>

        <Card title="Integrations">
          <Row label="Mail" value={<Badge tone={data.mail?.configured ? "green" : "amber"}>{data.mail?.configured ? "configured" : "not set"}</Badge>} />
          <Row label="Stripe mode" value={<Badge tone={data.stripe?.configured ? "green" : "amber"}>{data.stripe?.configured ? data.stripe?.mode : "not set"}</Badge>} />
          <Row label="AI provider" value={<Badge tone={data.ai?.configured ? "green" : "amber"}>{data.ai?.provider}</Badge>} />
        </Card>

        <Card title="Storage">
          <Row label="Reports disk" value={data.storage?.reports_disk} />
          <Row label="Writable" value={data.storage?.writable ? <Badge tone="green">yes</Badge> : <Badge tone="red">no</Badge>} />
          <Row label="Scheduler" value={data.scheduler?.detail || "configured"} />
        </Card>
      </div>
    </div>
  );
}
