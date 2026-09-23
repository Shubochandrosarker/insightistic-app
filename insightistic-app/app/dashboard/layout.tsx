"use client";
import { useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import {
  LayoutDashboard, Search, Sparkles, FileSearch, Gauge, BarChart3,
  ShoppingCart, Lightbulb, FileText, Settings, Globe, LogOut, X,
  Building2,
  type LucideIcon,
} from "lucide-react";
import { TrialBanner } from "@/components/dashboard/TrialBanner";
import { ImpersonationBanner } from "@/components/dashboard/ImpersonationBanner";
import { Protected } from "@/components/dashboard/Protected";
import { Topbar } from "@/components/dashboard/Topbar";
import { PlanCard } from "@/components/dashboard/PlanCard";
import { DashboardMobileControls } from "@/components/dashboard/DashboardMobileControls";
import { MobileTopbar } from "@/components/app-shell/MobileTopbar";
import { MobileBottomNav } from "@/components/app-shell/MobileBottomNav";
import { navActive } from "@/components/app-shell/DesktopSidebar";
import { CommandPalette } from "@/components/app-shell/CommandPalette";
import { NAV_GROUPS, type NavItem } from "@/lib/nav";
import { DashboardProvider } from "@/lib/dashboard";
import { useAuth } from "@/lib/auth";
import { BrandLogo } from "@/components/BrandLogo";

/** Icon ids are data in lib/nav.ts; the lucide mapping lives here. */
const GROUP_ICONS: Record<string, LucideIcon> = {
  LayoutDashboard, Search, Sparkles, FileSearch, Gauge,
  BarChart3, ShoppingCart, Lightbulb, FileText, Settings, Building2,
};

function groupIcon(name: string): LucideIcon {
  return GROUP_ICONS[name] ?? LayoutDashboard;
}

/** Every destination that exists today — feeds the command palette. */
const LINKS: { href: string; label: string; icon: LucideIcon }[] = NAV_GROUPS.flatMap((g) =>
  g.items
    .filter((i): i is NavItem & { href: string } => Boolean(i.href) && !i.soon)
    .map((i) => ({ href: i.href, label: i.label, icon: groupIcon(g.icon) })),
);

// Mobile bottom tabs stay the four most important destinations (spec §14).
const BOTTOM_NAV: { href: string; label: string; icon: LucideIcon }[] = [
  { href: "/dashboard", label: "Overview", icon: LayoutDashboard },
  { href: "/dashboard/traffic", label: "Traffic", icon: BarChart3 },
  { href: "/dashboard/insights", label: "Insights", icon: Sparkles },
  { href: "/dashboard/settings", label: "Sites", icon: Globe },
];

/**
 * Desktop sidebar (lg+): grouped target IA — section headers in small caps,
 * indented items, and "soon" rows that are deliberately non-interactive.
 */
function DesktopSidebar() {
  const path = usePathname();
  return (
    <aside className="ins-scroll hidden w-60 shrink-0 flex-col overflow-y-auto border-r border-line bg-card lg:flex">
      <div className="flex items-center gap-2 px-5 py-5">
        <BrandLogo className="rounded-md bg-white px-1.5" imageClassName="h-8 w-auto" />
      </div>

      <nav className="flex-1 px-3 pb-4" aria-label="Dashboard sections">
        {NAV_GROUPS.map((group) => {
          const GroupIcon = groupIcon(group.icon);
          return (
            <div key={group.label}>
              <div className="flex items-center gap-2 px-3 pb-1 pt-4 text-[10px] font-semibold uppercase tracking-widest text-muted">
                <GroupIcon size={13} className="shrink-0" /> {group.label}
              </div>
              {group.items.map((item) =>
                item.soon ? (
                  <div
                    key={item.label}
                    title={item.hint || `${item.label} — coming soon`}
                    aria-disabled="true"
                    className="mb-0.5 flex cursor-default items-center gap-2 rounded-xl py-1.5 pl-9 pr-3 text-[13px] text-muted/60"
                  >
                    <span className="truncate">{item.label}</span>
                    <span className="shrink-0 rounded-full bg-black/[0.06] px-1.5 py-px text-[9px] font-semibold uppercase tracking-wide text-muted dark:bg-white/10">
                      soon
                    </span>
                  </div>
                ) : (
                  <Link
                    key={item.label}
                    href={item.href as string}
                    aria-current={navActive(path, item.href as string) ? "page" : undefined}
                    className={`mb-0.5 flex items-center rounded-xl border py-2 pl-9 pr-3 text-sm transition ${
                      navActive(path, item.href as string)
                        ? "border-line bg-card font-semibold text-fg shadow-sm"
                        : "border-transparent text-muted hover:bg-black/[0.04] hover:text-fg dark:hover:bg-white/5"
                    }`}
                  >
                    <span className="truncate">{item.label}</span>
                  </Link>
                ),
              )}
            </div>
          );
        })}
      </nav>

      <div className="p-3">
        <PlanCard />
      </div>
    </aside>
  );
}

/**
 * Mobile "More" sheet: same groups as the desktop sidebar, but "soon" rows
 * are skipped to save space — a sheet you scroll forever is not navigation.
 */
function GroupedMoreSheet({
  open,
  onClose,
  onLogout,
}: {
  open: boolean;
  onClose: () => void;
  onLogout?: () => void;
}) {
  const path = usePathname();
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true">
      <button aria-label="Close menu" className="absolute inset-0 bg-black/40 backdrop-blur-[2px]" onClick={onClose} />
      <div className="ins-scroll absolute inset-x-0 bottom-0 max-h-[80vh] overflow-y-auto rounded-t-3xl border-t border-line bg-card p-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] animate-[ins-sheet-up_0.25s_ease]">
        <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-black/15 dark:bg-white/20" />
        <div className="mb-2 flex items-center justify-between">
          <h2 className="text-sm font-bold text-fg">More</h2>
          <button onClick={onClose} aria-label="Close" className="ins-tap flex h-9 w-9 items-center justify-center rounded-xl text-muted hover:bg-black/5 dark:hover:bg-white/10">
            <X size={18} />
          </button>
        </div>

        {NAV_GROUPS.map((group) => {
          const linkable = group.items.filter((i) => i.href && !i.soon);
          if (linkable.length === 0) return null;
          return (
            <div key={group.label} className="mb-3">
              <div className="mb-1.5 text-[10px] font-semibold uppercase tracking-widest text-muted">{group.label}</div>
              <div className="grid grid-cols-3 gap-2">
                {linkable.map((item) => {
                  const href = item.href as string;
                  const active = navActive(path, href);
                  return (
                    <Link
                      key={item.label}
                      href={href}
                      onClick={onClose}
                      aria-current={active ? "page" : undefined}
                      className={`ins-tap flex min-h-[56px] items-center justify-center rounded-2xl border p-2 text-center text-xs font-medium ${
                        active ? "border-brand/30 bg-brand/10 text-brand-700" : "border-line bg-card2 text-fg"
                      }`}
                    >
                      <span className="line-clamp-2">{item.label}</span>
                    </Link>
                  );
                })}
              </div>
            </div>
          );
        })}

        {onLogout && (
          <button
            onClick={() => { onClose(); onLogout(); }}
            className="ins-tap mt-3 flex w-full items-center justify-center gap-2 rounded-2xl border border-line bg-card2 py-3 text-sm font-semibold text-bad"
          >
            <LogOut size={17} /> Sign out
          </button>
        )}
      </div>
    </div>
  );
}

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const { logout } = useAuth();
  const [more, setMore] = useState(false);

  return (
    <Protected>
      <DashboardProvider>
        <div className="flex min-h-screen bg-bg text-fg">
          <DesktopSidebar />

          <div className="flex min-w-0 flex-1 flex-col">
            <div className="hidden lg:block">
              <Topbar />
            </div>
            <MobileTopbar>
              <DashboardMobileControls />
            </MobileTopbar>

            <main className="ins-page ins-scroll flex-1 overflow-x-hidden px-4 py-5 pb-safe-nav lg:px-8 lg:py-7 lg:pb-10">
              <div className="mx-auto max-w-[1200px]">
                {/* Cmd/Ctrl-K jump-to. Mounted inside the shell so it can reach
                    the same nav model the sidebar uses. */}
                <CommandPalette items={LINKS} />
                <ImpersonationBanner />
                <TrialBanner />
                {children}
              </div>
            </main>
          </div>

          <MobileBottomNav items={BOTTOM_NAV} onMore={() => setMore(true)} moreActive={more} />
          <GroupedMoreSheet open={more} onClose={() => setMore(false)} onLogout={logout} />
        </div>
      </DashboardProvider>
    </Protected>
  );
}
