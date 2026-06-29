"use client";
import Link from "next/link";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { SkeletonGrid } from "@/components/app-shell/SkeletonCard";
import { money0, num, timeAgo } from "@/lib/format";
import {
  Building2, Users, CreditCard, Clock, Globe, AlertTriangle,
  FileText, Sparkles, DollarSign, Server,
} from "lucide-react";

function Stat({ icon, label, value, tone = "brand" }: { icon: React.ReactNode; label: string; value: string | number; tone?: "brand" | "violet" | "amber" | "red" }) {
  const bg = { brand: "bg-brand/10 text-brand", violet: "bg-violet/12 text-violet", amber: "bg-warn/15 text-warn", red: "bg-bad/12 text-bad" }[tone];
  return (
    <div className="rounded-2xl border border-line bg-card p-4 shadow-card">
      <div className="flex items-center justify-between">
        <span className="text-xs font-medium text-muted">{label}</span>
        <span className={`flex h-7 w-7 items-center justify-center rounded-lg ${bg}`}>{icon}</span>
      </div>
      <div className="mt-1.5 text-[24px] font-bold text-fg">{value}</div>
    </div>
  );
}

export default function AdminOverview() {
  const { data, loading } = useApi<any>("/admin/overview");

  if (loading || !data) {
    return (
      <div>
        <PageHeader title="Platform overview" subtitle="Everything across every organization." />
        <SkeletonGrid count={10} />
      </div>
    );
  }

  const c = data.cards || {};

  return (
    <div>
      <PageHeader title="Platform overview" subtitle="Everything across every organization." />

      <div className="grid grid-cols-2 gap-3.5 md:grid-cols-3 lg:grid-cols-5">
        <Stat icon={<Building2 size={15} />} label="Organizations" value={num(c.total_organizations)} />
        <Stat icon={<Users size={15} />} label="Users" value={num(c.total_users)} tone="violet" />
        <Stat icon={<CreditCard size={15} />} label="Active subs" value={num(c.active_subscriptions)} />
        <Stat icon={<Clock size={15} />} label="Trials" value={num(c.trial_accounts)} tone="amber" />
        <Stat icon={<Globe size={15} />} label="Connected sites" value={num(c.connected_sites)} />
        <Stat icon={<AlertTriangle size={15} />} label="Failed syncs" value={num(c.failed_syncs)} tone="red" />
        <Stat icon={<FileText size={15} />} label="Reports" value={num(c.reports_generated)} />
        <Stat icon={<Sparkles size={15} />} label="AI insights" value={num(c.ai_insights_used)} tone="violet" />
        <Stat icon={<DollarSign size={15} />} label="MRR" value={money0(c.mrr)} />
        <Stat icon={<Server size={15} />} label="Queue pending" value={data.queue?.pending ?? "—"} tone="amber" />
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <Card title="Recent organizations" right={<Link href="/admin/organizations" className="text-xs font-semibold text-brand-700">View all</Link>}>
          <div className="space-y-1">
            {(data.recent_organizations || []).map((o: any) => (
              <Link key={o.id} href={`/admin/organizations/${o.id}`} className="flex items-center justify-between border-b border-line py-2.5 last:border-0">
                <div>
                  <div className="text-sm font-medium text-fg">{o.name}</div>
                  <div className="text-[11px] text-muted">{o.owner?.email || o.slug}</div>
                </div>
                <Badge tone={o.status === "active" ? "green" : o.status === "trialing" ? "amber" : "slate"}>{o.status}</Badge>
              </Link>
            ))}
            {(!data.recent_organizations || !data.recent_organizations.length) && <p className="py-4 text-center text-sm text-muted">No organizations yet.</p>}
          </div>
        </Card>

        <Card title="Recent failed syncs" right={<Link href="/admin/sync-logs" className="text-xs font-semibold text-brand-700">View all</Link>}>
          <div className="space-y-1">
            {(data.recent_failed_syncs || []).map((l: any) => (
              <div key={l.id} className="flex items-center justify-between border-b border-line py-2.5 last:border-0">
                <div className="min-w-0">
                  <div className="truncate text-sm font-medium text-fg">{l.site?.name || `Site #${l.site_id}`} · {l.job}</div>
                  <div className="truncate text-[11px] text-muted">{l.message || "—"}</div>
                </div>
                <span className="shrink-0 text-[11px] text-muted">{timeAgo(l.created_at)}</span>
              </div>
            ))}
            {(!data.recent_failed_syncs || !data.recent_failed_syncs.length) && <p className="py-4 text-center text-sm text-muted">No failed syncs 🎉</p>}
          </div>
        </Card>

        <Card title="Recent users" right={<Link href="/admin/users" className="text-xs font-semibold text-brand-700">View all</Link>}>
          <div className="space-y-1">
            {(data.recent_users || []).map((u: any) => (
              <div key={u.id} className="flex items-center justify-between border-b border-line py-2.5 last:border-0">
                <div>
                  <div className="text-sm font-medium text-fg">{u.name}{u.is_super_admin && <Badge tone="violet" className="ml-2">admin</Badge>}</div>
                  <div className="text-[11px] text-muted">{u.email}</div>
                </div>
                <span className="text-[11px] text-muted">{timeAgo(u.created_at)}</span>
              </div>
            ))}
          </div>
        </Card>

        <Card title="Recent reports" right={<Link href="/admin/reports" className="text-xs font-semibold text-brand-700">View all</Link>}>
          <div className="space-y-1">
            {(data.recent_reports || []).map((r: any) => (
              <div key={r.id} className="flex items-center justify-between border-b border-line py-2.5 last:border-0">
                <div className="min-w-0">
                  <div className="truncate text-sm font-medium text-fg">{r.title}</div>
                  <div className="text-[11px] text-muted">{r.site?.name} · {r.report_type}</div>
                </div>
                <span className="text-[11px] text-muted">{timeAgo(r.created_at)}</span>
              </div>
            ))}
            {(!data.recent_reports || !data.recent_reports.length) && <p className="py-4 text-center text-sm text-muted">No reports yet.</p>}
          </div>
        </Card>
      </div>
    </div>
  );
}
