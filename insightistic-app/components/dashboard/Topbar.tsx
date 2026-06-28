"use client";
import { useState } from "react";
import { useAuth } from "@/lib/auth";
import { useDashboard } from "@/lib/dashboard";
import { ChevronDown, LogOut, Check } from "lucide-react";
import { ThemeToggle } from "./ThemeToggle";
import { initials, timeAgo } from "@/lib/format";

const TOPBAR_PERIODS = [
  { key: "today", label: "Today" },
  { key: "last_7_days", label: "7 days" },
  { key: "last_30_days", label: "30 days" },
  { key: "this_month", label: "This month" },
];

export function Topbar() {
  const { user, logout } = useAuth();
  const { sites, siteId, setSiteId, period, setPeriod } = useDashboard();
  const [siteMenu, setSiteMenu] = useState(false);
  const active = sites.find((s) => s.id === siteId);

  return (
    <header className="sticky top-0 z-20 flex flex-wrap items-center gap-3 border-b border-line bg-bg/85 px-5 py-3 backdrop-blur">
      {/* Site selector */}
      <div className="relative">
        <button
          onClick={() => setSiteMenu((m) => !m)}
          className="flex items-center gap-2.5 rounded-xl border border-line bg-card px-2.5 py-1.5 text-left shadow-sm"
        >
          <span className="flex h-7 w-7 items-center justify-center rounded-lg bg-brand text-xs font-bold text-white">
            {initials(active?.name)}
          </span>
          <span className="leading-tight">
            <span className="block text-sm font-semibold text-fg">{active?.name || "No site"}</span>
            <span className="block text-[11px] text-muted">{active?.domain || "Add a site"}</span>
          </span>
          <ChevronDown size={15} className="text-muted" />
        </button>
        {siteMenu && sites.length > 0 && (
          <div className="absolute left-0 z-30 mt-2 w-60 rounded-xl border border-line bg-card p-1.5 shadow-card">
            {sites.map((s) => (
              <button
                key={s.id}
                onClick={() => { setSiteId(s.id); setSiteMenu(false); }}
                className="flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-sm text-fg hover:bg-black/[0.04] dark:hover:bg-white/5"
              >
                <span>
                  <span className="block font-medium">{s.name}</span>
                  <span className="block text-[11px] text-muted">{s.domain}</span>
                </span>
                {s.id === siteId && <Check size={15} className="text-brand" />}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Period pills */}
      <div className="flex items-center gap-1 rounded-xl border border-line bg-card p-1 shadow-sm">
        {TOPBAR_PERIODS.map((p) => (
          <button
            key={p.key}
            onClick={() => setPeriod(p.key)}
            className={`rounded-lg px-3 py-1.5 text-xs font-medium transition ${
              period === p.key ? "bg-brand text-white" : "text-muted hover:text-fg"
            }`}
          >
            {p.label}
          </button>
        ))}
      </div>

      <div className="ml-auto flex items-center gap-2.5">
        {active?.last_sync_at && (
          <span className="hidden items-center gap-1.5 rounded-full bg-good/12 px-2.5 py-1 text-xs font-semibold text-good sm:inline-flex">
            <span className="h-1.5 w-1.5 rounded-full bg-good" />
            Synced {timeAgo(active.last_sync_at)}
          </span>
        )}
        <ThemeToggle />
        <span className="flex h-9 w-9 items-center justify-center rounded-full bg-violet text-xs font-bold text-white">
          {initials(user?.name)}
        </span>
        <button
          onClick={logout}
          aria-label="Sign out"
          className="flex h-9 w-9 items-center justify-center rounded-xl border border-line bg-card text-muted hover:text-bad"
        >
          <LogOut size={16} />
        </button>
      </div>
    </header>
  );
}
