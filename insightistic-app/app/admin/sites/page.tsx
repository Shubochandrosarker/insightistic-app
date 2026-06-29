"use client";
import Link from "next/link";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";
import { timeAgo } from "@/lib/format";

const TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  connected: "green", pending: "amber", disconnected: "red", error: "red",
};

export default function AdminSites() {
  return (
    <AdminListPage
      title="Sites"
      subtitle="Every connected WordPress / WooCommerce store."
      endpoint="/admin/sites"
      rowKey={(s: any) => s.id}
      searchPlaceholder="Search site name or domain…"
      empty="No sites found."
      filters={[
        { key: "connection_status", label: "Any status", options: ["connected", "pending", "disconnected"].map((v) => ({ value: v, label: v })) },
      ]}
      columns={[
        {
          key: "name", label: "Site", primary: true,
          render: (s: any) => (
            <Link href={`/admin/sites/${s.id}`} className="hover:underline">
              <span className="block font-medium text-fg">{s.name}</span>
              <span className="block text-[11px] text-muted">{s.domain || "—"}</span>
            </Link>
          ),
        },
        { key: "organization", label: "Organization", render: (s: any) => s.organization?.name || "—" },
        { key: "connection_status", label: "Status", render: (s: any) => <Badge tone={TONE[s.connection_status] || "slate"} dot>{s.connection_status}</Badge> },
        { key: "plugin_version", label: "Plugin", align: "right", render: (s: any) => s.plugin_version ? `v${s.plugin_version}` : "—" },
        { key: "last_sync_at", label: "Last sync", align: "right", render: (s: any) => <span className="text-muted">{s.last_sync_at ? timeAgo(s.last_sync_at) : "never"}</span> },
      ]}
    />
  );
}
