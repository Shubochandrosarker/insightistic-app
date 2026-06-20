"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard, Sparkles, DollarSign, Package, Users, FileText,
  CreditCard, Palette, UserCog, Settings,
} from "lucide-react";

const items = [
  { href: "/dashboard", label: "Overview", icon: LayoutDashboard },
  { href: "/dashboard/insights", label: "AI Insights", icon: Sparkles },
  { href: "/dashboard/revenue", label: "Revenue", icon: DollarSign },
  { href: "/dashboard/products", label: "Products", icon: Package },
  { href: "/dashboard/customers", label: "Customers", icon: Users },
  { href: "/dashboard/reports", label: "Reports", icon: FileText },
  { href: "/dashboard/billing", label: "Billing", icon: CreditCard },
  { href: "/dashboard/branding", label: "White Label", icon: Palette },
  { href: "/dashboard/team", label: "Team", icon: UserCog },
  { href: "/dashboard/settings", label: "Settings", icon: Settings },
];

export function Sidebar() {
  const path = usePathname();
  return (
    <aside className="hidden w-56 shrink-0 border-r border-line bg-panel md:block">
      <div className="px-5 py-4 text-lg font-semibold text-white">Insightistic</div>
      <nav className="px-2">
        {items.map(({ href, label, icon: Icon }) => {
          const active = path === href;
          return (
            <Link key={href} href={href}
              className={`mb-1 flex items-center gap-3 rounded-lg px-3 py-2 text-sm ${
                active ? "bg-brand text-white" : "text-slate-300 hover:bg-ink"
              }`}>
              <Icon size={16} /> {label}
            </Link>
          );
        })}
      </nav>
    </aside>
  );
}
