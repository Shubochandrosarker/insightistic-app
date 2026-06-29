"use client";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";
import { apiPatch } from "@/lib/api";
import { num, timeAgo, initials } from "@/lib/format";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", disabled: "red", suspended: "amber", invited: "slate",
};

export default function AdminUsers() {
  async function toggle(u: any, reload: () => void) {
    const next = u.status === "active" ? "disabled" : "active";
    try { await apiPatch(`/admin/users/${u.id}`, { status: next }); reload(); } catch {}
  }

  return (
    <AdminListPage
      title="Users"
      subtitle="Every account across all organizations."
      endpoint="/admin/users"
      rowKey={(u: any) => u.id}
      searchPlaceholder="Search name or email…"
      empty="No users found."
      filters={[
        { key: "super_admin", label: "All users", options: [{ value: "1", label: "Super admins" }, { value: "0", label: "Standard" }] },
        { key: "status", label: "Any status", options: ["active", "disabled", "suspended"].map((s) => ({ value: s, label: s })) },
      ]}
      columns={[
        {
          key: "name", label: "User", primary: true,
          render: (u: any) => (
            <span className="flex items-center gap-3">
              <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand/15 text-xs font-bold text-brand-700">{initials(u.name)}</span>
              <span>
                <span className="flex items-center gap-2 font-medium text-fg">{u.name}{u.is_super_admin && <Badge tone="violet">admin</Badge>}</span>
                <span className="block text-[11px] text-muted">{u.email}</span>
              </span>
            </span>
          ),
        },
        { key: "organizations_count", label: "Orgs", align: "right", render: (u: any) => num(u.organizations_count) },
        { key: "status", label: "Status", render: (u: any) => <Badge tone={STATUS_TONE[u.status] || "slate"} dot>{u.status || "active"}</Badge> },
        { key: "last_login_at", label: "Last login", align: "right", render: (u: any) => <span className="text-muted">{u.last_login_at ? timeAgo(u.last_login_at) : "never"}</span> },
      ]}
      actions={(u: any, reload) => (
        u.is_super_admin
          ? <span className="text-[11px] text-muted">protected</span>
          : <button onClick={() => toggle(u, reload)} className={`rounded-lg border border-line px-2.5 py-1 text-xs font-semibold ${u.status === "active" ? "text-bad" : "text-good"}`}>
              {u.status === "active" ? "Disable" : "Enable"}
            </button>
      )}
    />
  );
}
