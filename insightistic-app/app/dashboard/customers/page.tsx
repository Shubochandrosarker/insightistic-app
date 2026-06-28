"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { money, num, initials } from "@/lib/format";
import { Users } from "lucide-react";

export default function CustomersPage() {
  const { siteId, period, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const cur = site?.currency as string | undefined;
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/analytics/customers?period=${period}` : null, [period]);

  if (!siteId) return <EmptyState icon={<Users size={20} />} title="No site connected" hint="Connect a store to see customer insights." />;
  if (loading || !data) return <Spinner />;

  const newC = data.new_customers ?? 0;
  const retC = data.returning_customers ?? 0;
  const returningRate = newC + retC > 0 ? Math.round((retC / (newC + retC)) * 100) : 0;
  const top = data.top_customers || [];
  const byCountry = data.by_country || [];
  const maxCountry = Math.max(1, ...byCountry.map((c: any) => c.customers || 0));

  return (
    <div>
      <PageHeader title="Customers" subtitle="Who’s buying, who’s loyal, and who’s slipping away." />

      <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
        {[
          { label: "New this period", value: num(newC) },
          { label: "Returning", value: num(retC) },
          { label: "Returning rate", value: `${returningRate}%` },
          { label: "Top spender", value: top[0] ? money(top[0].total_spent, cur) : "—" },
        ].map((k) => (
          <div key={k.label} className="rounded-2xl border border-line bg-card p-4 shadow-card">
            <div className="text-xs font-medium text-muted">{k.label}</div>
            <div className="mt-1.5 text-[26px] font-bold text-fg">{k.value}</div>
          </div>
        ))}
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-2">
        <Card title="Top customers by lifetime value">
          {top.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted">No customers yet.</p>
          ) : (
            <div className="space-y-1">
              {top.slice(0, 8).map((c: any, i: number) => {
                const name = `${c.first_name || ""} ${c.last_name || ""}`.trim() || `#${c.external_customer_id}`;
                return (
                  <div key={i} className="flex items-center gap-3 border-b border-line py-2.5 last:border-0">
                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-brand/15 text-xs font-bold text-brand-700">{initials(name)}</span>
                    <div className="min-w-0 flex-1">
                      <div className="truncate text-sm font-medium text-fg">{name}</div>
                      <div className="text-[11px] text-muted">{num(c.order_count)} orders</div>
                    </div>
                    <div className="text-sm font-semibold text-fg">{money(c.total_spent, cur)}</div>
                  </div>
                );
              })}
            </div>
          )}
        </Card>

        <Card title="Customers by location">
          {byCountry.length === 0 ? (
            <p className="py-8 text-center text-sm text-muted">No location data.</p>
          ) : (
            <div className="space-y-3">
              {byCountry.slice(0, 8).map((c: any, i: number) => (
                <div key={i}>
                  <div className="mb-1 flex items-center justify-between text-sm">
                    <span className="text-fg">{c.country || "Unknown"}</span>
                    <span className="font-semibold text-muted">{num(c.customers)}</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
                    <div className="h-full rounded-full bg-brand" style={{ width: `${Math.round(((c.customers || 0) / maxCountry) * 100)}%` }} />
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}
