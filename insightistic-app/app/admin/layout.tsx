"use client";
import {
  LayoutGrid, Building2, Users, Globe, CreditCard, RefreshCw,
  FileText, BarChart3, Activity, Settings,
} from "lucide-react";
import { AdminProtected } from "@/components/admin/AdminProtected";
import { AdminTopbar } from "@/components/admin/AdminTopbar";
import { AppShell } from "@/components/app-shell/AppShell";
import type { NavItem } from "@/components/app-shell/DesktopSidebar";
import { ThemeToggle } from "@/components/dashboard/ThemeToggle";
import { useAuth } from "@/lib/auth";
import { initials } from "@/lib/format";

const NAV: NavItem[] = [
  { href: "/admin", label: "Overview", icon: LayoutGrid },
  { href: "/admin/organizations", label: "Organizations", icon: Building2 },
  { href: "/admin/users", label: "Users", icon: Users },
  { href: "/admin/sites", label: "Sites", icon: Globe },
  { href: "/admin/subscriptions", label: "Subscriptions", icon: CreditCard },
  { href: "/admin/sync-logs", label: "Sync Logs", icon: RefreshCw },
  { href: "/admin/reports", label: "Reports", icon: FileText },
  { href: "/admin/usage", label: "Usage", icon: BarChart3 },
  { href: "/admin/system-health", label: "System Health", icon: Activity },
  { href: "/admin/settings", label: "Settings", icon: Settings },
];

const BOTTOM_NAV: NavItem[] = [
  { href: "/admin", label: "Overview", icon: LayoutGrid },
  { href: "/admin/organizations", label: "Orgs", icon: Building2 },
  { href: "/admin/users", label: "Users", icon: Users },
  { href: "/admin/sites", label: "Sites", icon: Globe },
];

const MORE: NavItem[] = [
  { href: "/admin/subscriptions", label: "Subscriptions", icon: CreditCard },
  { href: "/admin/sync-logs", label: "Sync Logs", icon: RefreshCw },
  { href: "/admin/reports", label: "Reports", icon: FileText },
  { href: "/admin/usage", label: "Usage", icon: BarChart3 },
  { href: "/admin/system-health", label: "System Health", icon: Activity },
  { href: "/admin/settings", label: "Settings", icon: Settings },
];

export default function AdminLayout({ children }: { children: React.ReactNode }) {
  const { user, logout } = useAuth();
  return (
    <AdminProtected>
      <AppShell
        brand="Insightistic Admin"
        items={NAV}
        bottomNav={BOTTOM_NAV}
        moreItems={MORE}
        desktopHeader={<AdminTopbar />}
        mobileHeader={
          <>
            <ThemeToggle />
            <span className="flex h-8 w-8 items-center justify-center rounded-full bg-violet text-[11px] font-bold text-white">
              {initials(user?.name)}
            </span>
          </>
        }
        onLogout={logout}
      >
        {children}
      </AppShell>
    </AdminProtected>
  );
}
