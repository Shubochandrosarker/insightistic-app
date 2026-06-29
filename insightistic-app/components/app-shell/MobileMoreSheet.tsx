"use client";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { LogOut, X } from "lucide-react";
import { navActive, type NavItem } from "./DesktopSidebar";

/** Slide-up bottom sheet with the secondary nav (mobile only). */
export function MobileMoreSheet({
  open,
  onClose,
  items,
  onLogout,
}: {
  open: boolean;
  onClose: () => void;
  items: NavItem[];
  onLogout?: () => void;
}) {
  const path = usePathname();
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true">
      <button aria-label="Close menu" className="absolute inset-0 bg-black/40 backdrop-blur-[2px]" onClick={onClose} />
      <div className="absolute inset-x-0 bottom-0 rounded-t-3xl border-t border-line bg-card p-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] animate-[ins-sheet-up_0.25s_ease]">
        <div className="mx-auto mb-3 h-1.5 w-10 rounded-full bg-black/15 dark:bg-white/20" />
        <div className="mb-2 flex items-center justify-between">
          <h2 className="text-sm font-bold text-fg">More</h2>
          <button onClick={onClose} aria-label="Close" className="ins-tap flex h-9 w-9 items-center justify-center rounded-xl text-muted hover:bg-black/5 dark:hover:bg-white/10">
            <X size={18} />
          </button>
        </div>

        <div className="grid grid-cols-3 gap-2">
          {items.map(({ href, label, icon: Icon }) => {
            const active = navActive(path, href);
            return (
              <Link
                key={href}
                href={href}
                onClick={onClose}
                className={`ins-tap flex min-h-[76px] flex-col items-center justify-center gap-1.5 rounded-2xl border p-2 text-center text-xs font-medium ${
                  active ? "border-brand/30 bg-brand/10 text-brand-700" : "border-line bg-card2 text-fg"
                }`}
              >
                <Icon size={20} className={active ? "text-brand" : "text-muted"} />
                {label}
              </Link>
            );
          })}
        </div>

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
