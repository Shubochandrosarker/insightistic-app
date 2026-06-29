"use client";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { money, timeAgo } from "@/lib/format";
import { ShoppingCart } from "lucide-react";

type Order = { order_number: string; customer: string; status: string; total: number; currency?: string; payment_method?: string; placed_at?: string };

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "violet" | "slate"> = {
  completed: "green", processing: "amber", "on-hold": "amber",
  pending: "slate", refunded: "violet", failed: "red", cancelled: "red",
};

export default function OrdersPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const { data, loading } = useApi<any>(siteId ? `/sites/${siteId}/orders` : null);

  if (!siteId) return <EmptyState icon={<ShoppingCart size={20} />} title="No site connected" hint="Connect a store to see its order feed." />;

  const orders: Order[] = data?.orders || [];

  const columns: Column<Order>[] = [
    { key: "order_number", label: "Order", primary: true, render: (o) => <span className="font-semibold text-brand-700">{o.order_number}</span> },
    { key: "customer", label: "Customer" },
    { key: "status", label: "Status", render: (o) => <Badge tone={STATUS_TONE[o.status] || "slate"} dot>{o.status}</Badge> },
    { key: "total", label: "Total", align: "right", render: (o) => <span className="font-semibold">{money(o.total, o.currency)}</span> },
    { key: "payment_method", label: "Method", render: (o) => o.payment_method || "—" },
    { key: "placed_at", label: "When", align: "right", render: (o) => <span className="text-muted">{timeAgo(o.placed_at)}</span> },
  ];

  return (
    <div>
      <PageHeader title="Orders" subtitle={`Live order feed for ${site?.name || "your store"}.`} />
      <Card>
        {loading ? <Spinner /> : <ResponsiveTable columns={columns} rows={orders} rowKey={(o, i) => `${o.order_number}-${i}`} empty="No orders synced yet." />}
      </Card>
    </div>
  );
}
