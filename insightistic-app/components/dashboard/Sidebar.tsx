"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutGrid, Sparkles, TrendingUp, Package, Users, ShoppingCart, Mail,
  Bell, FileText, Activity, Globe, UsersRound, CreditCard, Settings,
} from "lucide-react";
import { PlanCard } from "./PlanCard";

const items = [
  { href: "/dashboard", label: "Overview", icon: LayoutGrid },
  { href: "/dashboard/insights", label: "AI Insights", icon: Sparkles },
  { href: "/dashboard/revenue", label: "Revenue", icon: TrendingUp },
  { href: "/dashboard/products", label: "Products", icon: Package },
  { href: "/dashboard/customers", label: "Customers", icon: Users },
  { href: "/dashboard/orders", label: "Orders", icon: ShoppingCart },
  { href: "/dashboard/emails", label: "Emails", icon: Mail },
  { href: "/dashboard/alerts", label: "Alerts", icon: Bell },
  { href: "/dashboard/reports", label: "Reports", icon: FileText },
  { href: "/dashboard/site-health", label: "Site Health", icon: Activity },
  { href: "/dashboard/branding", label: "White Label", icon: Globe },
  { href: "/dashboard/team", label: "Team", icon: UsersRound },
  { href: "/dashboard/billing", label: "Billing", icon: CreditCard },
  { href: "/dashboard/settings", label: "Settings", icon: Settings },
];

export function Sidebar() {
  const path = usePathname();
  return (
    <aside className="ins-scroll hidden w-60 shrink-0 flex-col overflow-y-auto border-r border-line bg-card lg:flex">
      <div className="flex items-center gap-2 px-5 py-5">
        <span className="ins-logo-dot" />
        <span className="text-lg font-bold tracking-tight text-fg">Insightistic</span>
      </div>

      <nav className="flex-1 px-3">
        {items.map(({ href, label, icon: Icon }) => {
          const active = path === href;
          return (
            <Link
              key={href}
              href={href}
              className={`mb-0.5 flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition ${
                active
                  ? "border border-line bg-card font-semibold text-fg shadow-sm"
                  : "border border-transparent text-muted hover:bg-black/[0.04] hover:text-fg dark:hover:bg-white/5"
              }`}
            >
              <Icon size={17} className={active ? "text-brand" : ""} />
              {label}
            </Link>
          );
        })}
      </nav>

      <div className="p-3">
        <PlanCard />
      </div>
    </aside>
  );
}
