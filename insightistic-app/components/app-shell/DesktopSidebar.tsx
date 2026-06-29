"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import type { LucideIcon } from "lucide-react";
import type { ReactNode } from "react";

export type NavItem = { href: string; label: string; icon: LucideIcon };

export function navActive(path: string, href: string): boolean {
  if (path === href) return true;
  // sub-routes (3+ segments) match by prefix; section roots match exactly.
  return href.split("/").length > 2 && path.startsWith(href);
}

export function DesktopSidebar({
  items,
  brand = "Insightistic",
  footer,
}: {
  items: NavItem[];
  brand?: string;
  footer?: ReactNode;
}) {
  const path = usePathname();
  return (
    <aside className="ins-scroll hidden w-60 shrink-0 flex-col overflow-y-auto border-r border-line bg-card lg:flex">
      <div className="flex items-center gap-2 px-5 py-5">
        <span className="ins-logo-dot" />
        <span className="text-lg font-bold tracking-tight text-fg">{brand}</span>
      </div>

      <nav className="flex-1 px-3">
        {items.map(({ href, label, icon: Icon }) => {
          const active = navActive(path, href);
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
              <Icon size={17} className={active ? "text-brand" : ""} /> {label}
            </Link>
          );
        })}
      </nav>

      {footer && <div className="p-3">{footer}</div>}
    </aside>
  );
}
