"use client";
import { useState, type ReactNode } from "react";
import { DesktopSidebar, type NavItem } from "./DesktopSidebar";
import { MobileTopbar } from "./MobileTopbar";
import { MobileBottomNav } from "./MobileBottomNav";
import { MobileMoreSheet } from "./MobileMoreSheet";

/**
 * One responsive frame for the whole authed app.
 *   Desktop (lg+): left sidebar + top bar + content.
 *   Mobile (<lg): sticky top bar + content + fixed bottom tab bar + more sheet.
 */
export function AppShell({
  brand = "Insightistic",
  items,
  bottomNav,
  moreItems,
  sidebarFooter,
  desktopHeader,
  mobileHeader,
  onLogout,
  children,
}: {
  brand?: string;
  items: NavItem[];
  bottomNav: NavItem[];
  moreItems: NavItem[];
  sidebarFooter?: ReactNode;
  desktopHeader?: ReactNode;
  mobileHeader?: ReactNode;
  onLogout?: () => void;
  children: ReactNode;
}) {
  const [more, setMore] = useState(false);

  return (
    <div className="flex min-h-screen bg-bg text-fg">
      <DesktopSidebar items={items} brand={brand} footer={sidebarFooter} />

      <div className="flex min-w-0 flex-1 flex-col">
        {desktopHeader && <div className="hidden lg:block">{desktopHeader}</div>}
        <MobileTopbar brand={brand}>{mobileHeader}</MobileTopbar>

        <main className="ins-page ins-scroll flex-1 overflow-x-hidden px-4 py-5 pb-safe-nav lg:px-8 lg:py-7 lg:pb-10">
          <div className="mx-auto max-w-[1200px]">{children}</div>
        </main>
      </div>

      <MobileBottomNav items={bottomNav} onMore={() => setMore(true)} moreActive={more} />
      <MobileMoreSheet open={more} onClose={() => setMore(false)} items={moreItems} onLogout={onLogout} />
    </div>
  );
}
