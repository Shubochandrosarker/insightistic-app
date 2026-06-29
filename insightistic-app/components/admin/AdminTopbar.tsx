"use client";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import { ThemeToggle } from "@/components/dashboard/ThemeToggle";
import { initials } from "@/lib/format";
import { ExternalLink, LogOut, Shield } from "lucide-react";

/** Desktop top bar for the admin shell. */
export function AdminTopbar() {
  const { user, logout } = useAuth();
  return (
    <header className="sticky top-0 z-20 flex items-center gap-3 border-b border-line bg-bg/85 px-5 py-3 backdrop-blur">
      <span className="inline-flex items-center gap-1.5 rounded-full bg-violet/12 px-2.5 py-1 text-xs font-semibold text-violet">
        <Shield size={13} /> Platform admin
      </span>
      <Link href="/dashboard" className="inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg">
        <ExternalLink size={14} /> Open dashboard
      </Link>
      <div className="ml-auto flex items-center gap-2.5">
        <ThemeToggle />
        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-violet text-xs font-bold text-white">
          {initials(user?.name)}
        </span>
        <button onClick={logout} aria-label="Sign out" className="flex h-9 w-9 items-center justify-center rounded-xl border border-line bg-card text-muted hover:text-bad">
          <LogOut size={16} />
        </button>
      </div>
    </header>
  );
}
