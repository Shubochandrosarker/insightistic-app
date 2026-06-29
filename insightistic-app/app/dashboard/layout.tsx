"use client";
import {
  LayoutGrid, Sparkles, TrendingUp, Package, Users, ShoppingCart, Mail,
  Bell, FileText, Activity, Globe, UsersRound, CreditCard, Settings,
} from "lucide-react";
import { Protected } from "@/components/dashboard/Protected";
import { Topbar } from "@/components/dashboard/Topbar";
import { PlanCard } from "@/components/dashboard/PlanCard";
import { DashboardMobileControls } from "@/components/dashboard/DashboardMobileControls";
import { AppShell } from "@/components/app-shell/AppShell";
import type { NavItem } from "@/components/app-shell/DesktopSidebar";
import { DashboardProvider } from "@/lib/dashboard";
import { useAuth } from "@/lib/auth";

const NAV: NavItem[] = [
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

// Mobile bottom tab bar — 4 primary destinations (+ auto "More").
const BOTTOM_NAV: NavItem[] = [
  { href: "/dashboard", label: "Overview", icon: LayoutGrid },
  { href: "/dashboard/insights", label: "Insights", icon: Sparkles },
  { href: "/dashboard/reports", label: "Reports", icon: FileText },
  { href: "/dashboard/settings", label: "Sites", icon: Globe },
];

// Everything else lives in the mobile "More" sheet.
const MORE: NavItem[] = [
  { href: "/dashboard/revenue", label: "Revenue", icon: TrendingUp },
  { href: "/dashboard/products", label: "Products", icon: Package },
  { href: "/dashboard/customers", label: "Customers", icon: Users },
  { href: "/dashboard/orders", label: "Orders", icon: ShoppingCart },
  { href: "/dashboard/emails", label: "Emails", icon: Mail },
  { href: "/dashboard/alerts", label: "Alerts", icon: Bell },
  { href: "/dashboard/site-health", label: "Site Health", icon: Activity },
  { href: "/dashboard/branding", label: "White Label", icon: Globe },
  { href: "/dashboard/team", label: "Team", icon: UsersRound },
  { href: "/dashboard/billing", label: "Billing", icon: CreditCard },
  { href: "/dashboard/settings", label: "Settings", icon: Settings },
];

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { logout } = useAuth();
  return (
    <Protected>
      <DashboardProvider>
        <AppShell
          items={NAV}
          bottomNav={BOTTOM_NAV}
          moreItems={MORE}
          sidebarFooter={<PlanCard />}
          desktopHeader={<Topbar />}
          mobileHeader={<DashboardMobileControls />}
          onLogout={logout}
        >
          {children}
        </AppShell>
      </DashboardProvider>
    </Protected>
  );
}
