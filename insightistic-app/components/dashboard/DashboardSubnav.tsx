"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronRight, Sparkles } from "lucide-react";
import { NAV_GROUPS } from "@/lib/nav";
import { navActive } from "@/components/app-shell/DesktopSidebar";

/**
 * Context navigation for the active product area. The primary rail chooses a
 * workspace; this strip exposes the workspace's actual destinations without
 * turning the left rail into a scrolling sitemap.
 */
export function DashboardSubnav() {
  const path = usePathname();
  const group = NAV_GROUPS.find((candidate) =>
    candidate.items.some((item) => item.href && navActive(path, item.href)),
  ) || NAV_GROUPS[0];
  const items = group.items.filter((item) => item.href && !item.soon);

  return (
    <nav
      aria-label={`${group.label} navigation`}
      className="dashboard-subnav border-b border-line bg-card/90 px-4 backdrop-blur lg:px-8"
    >
      <div className="mx-auto flex max-w-[1200px] items-center gap-3 overflow-x-auto py-2.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <div className="mr-1 flex shrink-0 items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-muted">
          {group.label === "Intelligence" ? <Sparkles size={13} className="text-violet" /> : null}
          {group.label}
          <ChevronRight size={13} className="text-muted/70" />
        </div>
        {items.map((item) => {
          const href = item.href as string;
          const active = navActive(path, href);
          return (
            <Link
              key={href}
              href={href}
              aria-current={active ? "page" : undefined}
              className={`dashboard-subnav__item shrink-0 rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                active
                  ? "bg-fg text-card shadow-sm"
                  : "text-muted hover:bg-card2 hover:text-fg"
              }`}
            >
              {item.label}
            </Link>
          );
        })}
      </div>
    </nav>
  );
}
