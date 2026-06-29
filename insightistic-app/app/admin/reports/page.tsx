"use client";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";
import { timeAgo } from "@/lib/format";

const TONE: Record<string, "green" | "violet" | "slate"> = { weekly: "green", monthly: "violet", custom: "slate" };
const fmt = (x?: string) => (x ? new Date(x).toLocaleDateString() : "—");

export default function AdminReports() {
  return (
    <AdminListPage
      title="Reports"
      subtitle="Every branded report generated across the platform."
      endpoint="/admin/reports"
      rowKey={(r: any) => r.id}
      searchPlaceholder="Search…"
      empty="No reports generated yet."
      filters={[
        { key: "report_type", label: "Any type", options: ["weekly", "monthly", "custom"].map((v) => ({ value: v, label: v })) },
      ]}
      columns={[
        {
          key: "title", label: "Report", primary: true,
          render: (r: any) => (
            <span>
              <span className="block font-medium text-fg">{r.title}</span>
              <span className="block text-[11px] text-muted">{r.organization?.name} · {r.site?.name}</span>
            </span>
          ),
        },
        { key: "report_type", label: "Type", render: (r: any) => <Badge tone={TONE[r.report_type] || "slate"}>{r.report_type}</Badge> },
        { key: "period", label: "Period", render: (r: any) => `${fmt(r.period_start)} → ${fmt(r.period_end)}` },
        { key: "created_at", label: "Generated", align: "right", render: (r: any) => <span className="text-muted">{timeAgo(r.created_at)}</span> },
      ]}
    />
  );
}
