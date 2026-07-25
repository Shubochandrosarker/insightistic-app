"use client";
import {
  LayoutGrid, Sparkles, TrendingUp, Package, Users, ShoppingCart, Mail,
  Bell, FileText, Activity, Globe, UsersRound, CreditCard, Settings, KeyRound,
  Search, Gauge, Plug, Bot, BookOpen, ShieldCheck, ShieldAlert,
} from "lucide-react";
import { TrialBanner } from "@/components/dashboard/TrialBanner";
import { ImpersonationBanner } from "@/components/dashboard/ImpersonationBanner";
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
  { href: "/dashboard/copilot", label: "AI Copilot", icon: Bot },
  { href: "/dashboard/knowledge", label: "Knowledge", icon: BookOpen },
  { href: "/dashboard/traffic", label: "Traffic", icon: TrendingUp },
  { href: "/dashboard/seo", label: "SEO", icon: Search },
  { href: "/dashboard/performance", label: "Performance", icon: Gauge },
  { href: "/dashboard/revenue", label: "Revenue", icon: TrendingUp },
  { href: "/dashboard/products", label: "Products", icon: Package },
  { href: "/dashboard/customers", label: "Customers", icon: Users },
  { href: "/dashboard/orders", label: "Orders", icon: ShoppingCart },
  { href: "/dashboard/emails", label: "Emails", icon: Mail },
  { href: "/dashboard/alerts", label: "Alerts", icon: Bell },
  { href: "/dashboard/reports", label: "Reports", icon: FileText },
  { href: "/dashboard/site-health", label: "Site Health", icon: Activity },
  { href: "/dashboard/integrations", label: "Integrations", icon: Plug },
  { href: "/dashboard/licenses", label: "Licenses", icon: KeyRound },
  { href: "/dashboard/branding", label: "White Label", icon: Globe },
  { href: "/dashboard/team", label: "Team", icon: UsersRound },
  { href: "/dashboard/billing", label: "Billing", icon: CreditCard },
  { href: "/dashboard/security", label: "Security", icon: ShieldCheck },
  { href: "/dashboard/privacy", label: "Privacy", icon: ShieldAlert },
  { href: "/dashboard/settings", label: "Settings", icon: Settings },
];

// Mobile bottom tab bar — 4 primary destinations (+ auto "More"). Order per
// spec §14: Overview, Traffic, Insights, More/Sites.
const BOTTOM_NAV: NavItem[] = [
  { href: "/dashboard", label: "Overview", icon: LayoutGrid },
  { href: "/dashboard/traffic", label: "Traffic", icon: TrendingUp },
  { href: "/dashboard/insights", label: "Insights", icon: Sparkles },
  { href: "/dashboard/settings", label: "Sites", icon: Globe },
];

// Everything else lives in the mobile "More" sheet.
const MORE: NavItem[] = [
  { href: "/dashboard/copilot", label: "AI Copilot", icon: Bot },
  { href: "/dashboard/knowledge", label: "Knowledge", icon: BookOpen },
  { href: "/dashboard/seo", label: "SEO", icon: Search },
  { href: "/dashboard/performance", label: "Performance", icon: Gauge },
  { href: "/dashboard/revenue", label: "Revenue", icon: TrendingUp },
  { href: "/dashboard/products", label: "Products", icon: Package },
  { href: "/dashboard/customers", label: "Customers", icon: Users },
  { href: "/dashboard/orders", label: "Orders", icon: ShoppingCart },
  { href: "/dashboard/emails", label: "Emails", icon: Mail },
  { href: "/dashboard/alerts", label: "Alerts", icon: Bell },
  { href: "/dashboard/reports", label: "Reports", icon: FileText },
  { href: "/dashboard/site-health", label: "Site Health", icon: Activity },
  { href: "/dashboard/integrations", label: "Integrations", icon: Plug },
  { href: "/dashboard/licenses", label: "Licenses", icon: KeyRound },
  { href: "/dashboard/branding", label: "White Label", icon: Globe },
  { href: "/dashboard/team", label: "Team", icon: UsersRound },
  { href: "/dashboard/billing", label: "Billing", icon: CreditCard },
  { href: "/dashboard/security", label: "Security", icon: ShieldCheck },
  { href: "/dashboard/privacy", label: "Privacy", icon: ShieldAlert },
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
          <ImpersonationBanner />
          <TrialBanner />
          {children}
        </AppShell>
      </DashboardProvider>
    </Protected>
  );
}
