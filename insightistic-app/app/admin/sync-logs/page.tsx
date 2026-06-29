"use client";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";
import { num, timeAgo } from "@/lib/format";

const TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  success: "green", partial: "amber", started: "slate", running: "slate", failed: "red",
};

export default function AdminSyncLogs() {
  return (
    <AdminListPage
      title="Sync Logs"
      subtitle="Connector sync activity across every site. Failures are flagged in red."
      endpoint="/admin/sync-logs"
      rowKey={(l: any) => l.id}
      searchPlaceholder="Search error messages…"
      empty="No sync activity yet."
      filters={[
        { key: "status", label: "Any status", options: ["success", "partial", "failed", "started"].map((v) => ({ value: v, label: v })) },
        { key: "job", label: "Any job", options: ["orders", "products", "customers", "site_health", "handshake"].map((v) => ({ value: v, label: v })) },
      ]}
      columns={[
        {
          key: "site", label: "Site / job", primary: true,
          render: (l: any) => (
            <span>
              <span className="block font-medium text-fg">{l.site?.name || `Site #${l.site_id}`}</span>
              <span className="block text-[11px] text-muted">{l.job}</span>
            </span>
          ),
        },
        { key: "status", label: "Status", render: (l: any) => <Badge tone={TONE[l.status] || "slate"} dot>{l.status}</Badge> },
        { key: "records", label: "Records", align: "right", render: (l: any) => num(l.records) },
        { key: "message", label: "Message", render: (l: any) => <span className="text-muted">{l.message || "—"}</span> },
        { key: "created_at", label: "When", align: "right", render: (l: any) => <span className="text-muted">{timeAgo(l.created_at)}</span> },
      ]}
    />
  );
}
