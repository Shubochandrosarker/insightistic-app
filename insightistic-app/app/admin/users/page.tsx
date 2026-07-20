"use client";
import { useRouter } from "next/navigation";
import { AdminListPage } from "@/components/admin/AdminListPage";
import { Badge } from "@/components/ui/Badge";
import { apiPatch, apiPost, getToken, setImpersonation } from "@/lib/api";
import { useAuth } from "@/lib/auth";
import { num, timeAgo, initials } from "@/lib/format";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", disabled: "red", suspended: "amber", invited: "slate",
};

export default function AdminUsers() {
  const router = useRouter();
  const { applyToken } = useAuth();

  async function toggle(u: any, reload: () => void) {
    const next = u.status === "active" ? "disabled" : "active";
    try { await apiPatch(`/admin/users/${u.id}`, { status: next }); reload(); }
    catch (e: any) { window.alert(e.message || "Failed."); }
  }

  async function togglePromotion(u: any, reload: () => void) {
    try { await apiPost(`/admin/users/${u.id}/${u.is_super_admin ? "demote" : "promote"}`); reload(); }
    catch (e: any) { window.alert(e.message || "Failed."); }
  }

  async function impersonate(u: any) {
    const reason = window.prompt(`Why are you viewing as ${u.name}? (required, visible in the audit log)`);
    if (!reason) return;
    const adminToken = getToken();
    if (!adminToken) return;
    try {
      const res = await apiPost("/admin/impersonate", { user_id: u.id, reason });
      setImpersonation(adminToken, res.impersonation);
      await applyToken(res.token);
      router.push("/dashboard");
    } catch (e: any) {
      window.alert(e.message || "Couldn't start impersonation.");
    }
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
        <span className="flex items-center justify-end gap-1.5">
          {!u.is_super_admin && (
            <button onClick={() => impersonate(u)} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">
              Impersonate
            </button>
          )}
          <button onClick={() => togglePromotion(u, reload)} className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-fg hover:bg-card2">
            {u.is_super_admin ? "Demote" : "Promote"}
          </button>
          <button onClick={() => toggle(u, reload)} className={`rounded-lg border border-line px-2.5 py-1 text-xs font-semibold ${u.status === "active" ? "text-bad" : "text-good"}`}>
            {u.status === "active" ? "Disable" : "Enable"}
          </button>
        </span>
      )}
    />
  );
}
