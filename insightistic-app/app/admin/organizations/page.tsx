"use client";
import Link from "next/link";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";
import { num } from "@/lib/format";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", trialing: "amber", past_due: "amber", suspended: "red", canceled: "slate",
};

export default function AdminOrganizations() {
  return (
    <AdminListPage
      title="Organizations"
      subtitle="Every tenant on the platform."
      endpoint="/admin/organizations"
      rowKey={(o: any) => o.id}
      searchPlaceholder="Search name, slug, or owner email…"
      empty="No organizations found."
      filters={[
        { key: "plan", label: "All plans", options: ["starter", "growth", "business", "agency", "agency_pro"].map((p) => ({ value: p, label: p })) },
        { key: "status", label: "All statuses", options: ["trialing", "active", "past_due", "suspended", "canceled"].map((s) => ({ value: s, label: s })) },
      ]}
      columns={[
        {
          key: "name", label: "Organization", primary: true,
          render: (o: any) => (
            <Link href={`/admin/organizations/${o.id}`} className="hover:underline">
              <span className="block font-medium text-fg">{o.name}</span>
              <span className="block text-[11px] text-muted">{o.owner?.email || o.slug}</span>
            </Link>
          ),
        },
        { key: "plan", label: "Plan", render: (o: any) => o.plan?.name || "—" },
        { key: "status", label: "Status", render: (o: any) => <Badge tone={STATUS_TONE[o.status] || "slate"} dot>{o.status}</Badge> },
        { key: "sites_count", label: "Sites", align: "right", render: (o: any) => num(o.sites_count) },
        { key: "users_count", label: "Team", align: "right", render: (o: any) => num(o.users_count) },
      ]}
    />
  );
}
