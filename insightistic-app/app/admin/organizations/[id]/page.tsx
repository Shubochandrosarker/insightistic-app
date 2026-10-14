"use client";
import { useState } from "react";
import { useParams } from "next/navigation";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { apiPatch } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { num, timeAgo } from "@/lib/format";
import { ArrowLeft } from "lucide-react";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  active: "green", trialing: "amber", past_due: "amber", suspended: "red", canceled: "slate",
};

export default function AdminOrgDetail() {
  const { id } = useParams<{ id: string }>();
  const { data, loading, error, reload } = useApi<any>(`/admin/organizations/${id}`);
  const plansApi = useApi<{ plans: any[] }>("/admin/plans");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  async function patch(payload: any) {
    setBusy(true);
    setErr(null);
    try {
      await apiPatch(`/admin/organizations/${id}`, payload);
      reload();
    } catch (e: any) {
      // Swallowing this silently reloaded the page unchanged and looked like
      // the edit had applied — a false success on an admin mutation.
      setErr(e?.message || "That change could not be saved.");
    } finally {
      setBusy(false);
    }
  }

  // 2026-10-13 incident pin: explicit error state — never spin forever.
  if (error && !data) {
    return (
      <div>
        <Link href="/admin/organizations" className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg">
          <ArrowLeft size={15} /> Organizations
        </Link>
        <Card>
          <div className="flex flex-col items-center gap-3 py-8 text-center">
            <Badge tone="red" dot>API error</Badge>
            <p className="max-w-md text-sm text-muted">{error}</p>
            <Button variant="ghost" onClick={reload}>Retry</Button>
          </div>
        </Card>
      </div>
    );
  }

  if (loading || !data) return <Spinner />;
  const o = data.organization || {};

  return (
    <div>
      {err && (
        <div className="mb-4 rounded-xl border border-bad/25 bg-bad/10 px-4 py-3 text-sm text-bad">{err}</div>
      )}
      <Link href="/admin/organizations" className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg">
        <ArrowLeft size={15} /> Organizations
      </Link>
      <PageHeader
        title={o.name}
        subtitle={`${o.slug} · created ${timeAgo(o.created_at)}`}
        right={<Badge tone={STATUS_TONE[o.status] || "slate"} dot>{o.status}</Badge>}
      />

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card title="Manage">
            <div className="flex flex-wrap items-end gap-3">
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold text-fg">Name</span>
                <input
                  key={o.name}
                  defaultValue={o.name}
                  onBlur={(e) => { const v = e.target.value.trim(); if (v && v !== o.name) patch({ name: v }); }}
                  disabled={busy}
                  className="w-56 rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"
                />
              </label>
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold text-fg">Plan</span>
                <select
                  defaultValue={data.plan?.slug || ""}
                  onChange={(e) => patch({ plan: e.target.value })}
                  disabled={busy}
                  className="rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"
                >
                  <option value="" disabled>Choose plan…</option>
                  {(plansApi.data?.plans || []).map((p: any) => <option key={p.slug} value={p.slug}>{p.name}</option>)}
                </select>
              </label>
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold text-fg">Owner email</span>
                <input
                  key={data.owner?.email}
                  defaultValue={data.owner?.email || ""}
                  onBlur={(e) => { const v = e.target.value.trim(); if (v && v !== data.owner?.email) patch({ owner_email: v }); }}
                  disabled={busy}
                  className="w-56 rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"
                />
              </label>
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold text-fg">Trial ends</span>
                <input
                  type="date"
                  key={o.trial_ends_at}
                  defaultValue={o.trial_ends_at ? o.trial_ends_at.slice(0, 10) : ""}
                  onBlur={(e) => patch({ trial_ends_at: e.target.value || null })}
                  disabled={busy}
                  className="rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand"
                />
              </label>
              {o.status === "suspended"
                ? <Button onClick={() => patch({ status: "active" })} disabled={busy}>Reactivate</Button>
                : <Button variant="danger" onClick={() => patch({ status: "suspended" })} disabled={busy}>Suspend</Button>}
            </div>
            <label className="mt-4 block">
              <span className="mb-1.5 block text-xs font-semibold text-fg">Internal notes</span>
              <textarea key={o.internal_notes} defaultValue={o.internal_notes || ""} onBlur={(e) => patch({ internal_notes: e.target.value })} disabled={busy} rows={3} className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand" />
            </label>
          </Card>

          <Card title={`Sites (${num(data.sites?.length)})`}>
            <div className="space-y-1">
              {(data.sites || []).map((s: any) => (
                <Link key={s.id} href={`/admin/sites/${s.id}`} className="flex items-center justify-between border-b border-line py-2.5 last:border-0">
                  <div><div className="text-sm font-medium text-fg">{s.name}</div><div className="text-[11px] text-muted">{s.domain}</div></div>
                  <Badge tone={s.connection_status === "connected" ? "green" : "amber"}>{s.connection_status}</Badge>
                </Link>
              ))}
              {(!data.sites || !data.sites.length) && <p className="py-4 text-center text-sm text-muted">No sites.</p>}
            </div>
          </Card>

          <Card title="Recent sync logs">
            <div className="space-y-1">
              {(data.recent_sync_logs || []).map((l: any) => (
                <div key={l.id} className="flex items-center justify-between border-b border-line py-2 text-sm last:border-0">
                  <span className="text-fg">{l.job}</span>
                  <span className="flex items-center gap-3">
                    <Badge tone={l.status === "success" ? "green" : l.status === "failed" ? "red" : "slate"}>{l.status}</Badge>
                    <span className="text-[11px] text-muted">{timeAgo(l.created_at)}</span>
                  </span>
                </div>
              ))}
              {(!data.recent_sync_logs || !data.recent_sync_logs.length) && <p className="py-4 text-center text-sm text-muted">No sync activity.</p>}
            </div>
          </Card>
        </div>

        <div className="space-y-5">
          <Card title="Profile">
            <dl className="space-y-2.5 text-sm">
              <div className="flex justify-between"><dt className="text-muted">Owner</dt><dd className="text-right font-medium text-fg">{data.owner?.name}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Owner email</dt><dd className="text-right text-fg">{data.owner?.email}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Plan</dt><dd className="text-right text-fg">{data.plan?.name || "—"}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Members</dt><dd className="text-right text-fg">{num(data.members?.length)}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">AI insights</dt><dd className="text-right text-fg">{num(data.ai_insights_count)}</dd></div>
            </dl>
          </Card>

          <Card title="Usage this period">
            <dl className="space-y-2.5 text-sm">
              <div className="flex justify-between"><dt className="text-muted">AI insights</dt><dd className="text-fg">{num(data.usage?.ai_insights_used)} / {data.plan?.ai_insight_limit ?? "∞"}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Reports</dt><dd className="text-fg">{num(data.usage?.reports_generated)} / {data.plan?.report_limit ?? "∞"}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Sites</dt><dd className="text-fg">{num(data.sites?.length)} / {data.plan?.site_limit ?? "∞"}</dd></div>
            </dl>
          </Card>

          <Card title="Subscription">
            {data.subscription ? (
              <dl className="space-y-2.5 text-sm">
                <div className="flex justify-between"><dt className="text-muted">Status</dt><dd className="text-fg">{data.subscription.status}</dd></div>
                <div className="flex justify-between"><dt className="text-muted">Customer</dt><dd className="font-mono text-[11px] text-muted">{data.subscription.stripe_customer_id || "—"}</dd></div>
              </dl>
            ) : <p className="text-sm text-muted">No Stripe subscription.</p>}
          </Card>
        </div>
      </div>
    </div>
  );
}
