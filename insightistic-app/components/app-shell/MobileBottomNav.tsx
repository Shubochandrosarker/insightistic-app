"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { MoreHorizontal } from "lucide-react";
import { navActive, type NavItem } from "./DesktopSidebar";

/** Fixed bottom tab bar (mobile only). 4 primary items + a More button. */
export function MobileBottomNav({ items, onMore, moreActive }: { items: NavItem[]; onMore: () => void; moreActive?: boolean }) {
  const path = usePathname();
  return (
    <nav className="bottom-safe fixed inset-x-0 bottom-0 z-40 border-t border-line bg-card/85 backdrop-blur-lg lg:hidden">
      <div className="mx-auto flex max-w-lg items-stretch justify-around">
        {items.map(({ href, label, icon: Icon }) => {
          const active = navActive(path, href);
          return (
            <Link
              key={href}
              href={href}
              aria-current={active ? "page" : undefined}
              className={`ins-tap relative flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-medium ${
                active ? "text-brand" : "text-muted"
              }`}
            >
              <span className={`absolute top-0 h-0.5 w-7 rounded-full bg-brand transition-opacity ${active ? "opacity-100" : "opacity-0"}`} />
              <Icon size={20} />
              {label}
            </Link>
          );
        })}
        <button
          type="button"
          onClick={onMore}
          aria-label="More"
          className={`ins-tap flex min-h-[56px] flex-1 flex-col items-center justify-center gap-0.5 py-2 text-[11px] font-medium ${
            moreActive ? "text-brand" : "text-muted"
          }`}
        >
          <MoreHorizontal size={20} />
          More
        </button>
      </div>
    </nav>
  );
}
