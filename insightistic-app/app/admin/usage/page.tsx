"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { Bars } from "@/components/dashboard/Charts";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { num } from "@/lib/format";

const ratio = (used?: number, limit?: number) => `${num(used || 0)} / ${limit ?? "∞"}`;

export default function AdminUsage() {
  const [page, setPage] = useState(1);
  const { data, loading } = useApi<any>(`/admin/usage?page=${page}`);

  if (loading && !data) {
    return (
      <div>
        <PageHeader title="Usage" subtitle="AI, reports and connection usage vs plan limits." />
        <Spinner />
      </div>
    );
  }

  const trend = (data?.trend || []).map((t: any) => ({ label: String(t.period).slice(2), v: Number(t.ai_insights) }));
  const orgs = data?.organizations?.data || [];
  const meta = data?.organizations || { current_page: 1, last_page: 1, total: 0 };

  const columns: Column<any>[] = [
    { key: "name", label: "Organization", primary: true, render: (o) => <span className="font-medium text-fg">{o.name}</span> },
    { key: "ai", label: "AI insights", align: "right", render: (o) => ratio(o.current_usage?.ai_insights_used, o.plan?.ai_insight_limit) },
    { key: "reports", label: "Reports", align: "right", render: (o) => ratio(o.current_usage?.reports_generated, o.plan?.report_limit) },
    { key: "sites", label: "Sites", align: "right", render: (o) => ratio(o.sites_count, o.plan?.site_limit) },
    { key: "users", label: "Users", align: "right", render: (o) => ratio(o.users_count, o.plan?.user_limit) },
  ];

  return (
    <div>
      <PageHeader title="Usage" subtitle="AI, reports and connection usage vs plan limits." />

      <Card className="mb-5" title="AI insights — platform trend" subtitle="Last 6 months">
        {trend.length ? <Bars data={trend} dataKey="v" height={200} /> : <p className="py-8 text-center text-sm text-muted">No usage recorded yet.</p>}
      </Card>

      <Card>
        <ResponsiveTable columns={columns} rows={orgs} rowKey={(o: any) => o.id} empty="No organizations yet." />
        {meta.total > 0 && (
          <div className="mt-4 flex items-center justify-between border-t border-line pt-3 text-xs text-muted">
            <span>Page {meta.current_page} of {meta.last_page} · {meta.total} total</span>
            <span className="flex gap-2">
              <button disabled={meta.current_page <= 1} onClick={() => setPage((p) => Math.max(1, p - 1))} className="rounded-lg border border-line px-3 py-1.5 font-semibold text-fg disabled:opacity-40">Prev</button>
              <button disabled={meta.current_page >= meta.last_page} onClick={() => setPage((p) => p + 1)} className="rounded-lg border border-line px-3 py-1.5 font-semibold text-fg disabled:opacity-40">Next</button>
            </span>
          </div>
        )}
      </Card>
    </div>
  );
}
