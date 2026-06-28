"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { money, timeAgo } from "@/lib/format";
import { ShoppingCart } from "lucide-react";

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "violet" | "slate"> = {
  completed: "green",
  processing: "amber",
  "on-hold": "amber",
  pending: "slate",
  refunded: "violet",
  failed: "red",
  cancelled: "red",
};

export default function OrdersPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/orders` : null);

  if (!siteId) return <EmptyState icon={<ShoppingCart size={20} />} title="No site connected" hint="Connect a store to see its order feed." />;

  const orders = data?.orders || [];

  return (
    <div>
      <PageHeader title="Orders" subtitle={`Live order feed for ${site?.name || "your store"}.`} />
      <Card>
        {loading ? (
          <Spinner />
        ) : orders.length === 0 ? (
          <p className="py-8 text-center text-sm text-muted">No orders synced yet.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-[11px] uppercase tracking-wide text-muted">
                  <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Order</th>
                  <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Customer</th>
                  <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Status</th>
                  <th className="border-b border-line pb-2.5 px-2 text-right font-semibold">Total</th>
                  <th className="border-b border-line pb-2.5 px-2 text-left font-semibold">Method</th>
                  <th className="border-b border-line pb-2.5 px-2 text-right font-semibold">When</th>
                </tr>
              </thead>
              <tbody>
                {orders.map((o: any, i: number) => (
                  <tr key={i} className="group">
                    <td className="border-b border-line py-3 px-2 font-semibold text-brand-700 group-last:border-0">{o.order_number}</td>
                    <td className="border-b border-line py-3 px-2 text-fg group-last:border-0">{o.customer}</td>
                    <td className="border-b border-line py-3 px-2 group-last:border-0">
                      <Badge tone={STATUS_TONE[o.status] || "slate"} dot>{o.status}</Badge>
                    </td>
                    <td className="border-b border-line py-3 px-2 text-right font-semibold text-fg group-last:border-0">{money(o.total, o.currency)}</td>
                    <td className="border-b border-line py-3 px-2 text-muted group-last:border-0">{o.payment_method || "—"}</td>
                    <td className="border-b border-line py-3 px-2 text-right text-muted group-last:border-0">{timeAgo(o.placed_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
