"use client";
import { useState } from "react";
import { useAuth } from "@/lib/auth";
import { useDashboard, PERIODS } from "@/lib/dashboard";
import { ChevronDown, Check } from "lucide-react";
import { ThemeToggle } from "./ThemeToggle";
import { initials } from "@/lib/format";

const MOBILE_PERIODS = ["today", "last_7_days", "last_30_days", "this_month"];

/** Compact controls for the mobile sticky header: site, period, theme, avatar. */
export function DashboardMobileControls() {
  const { user } = useAuth();
  const { sites, siteId, setSiteId, period, setPeriod } = useDashboard();
  const [menu, setMenu] = useState(false);
  const active = sites.find((s) => s.id === siteId);
  const periodLabel = PERIODS.find((p) => p.key === period)?.label ?? "30 days";

  return (
    <>
      {/* Site switcher */}
      <div className="relative">
        <button
          onClick={() => setMenu((m) => !m)}
          className="ins-tap flex h-8 items-center gap-1 rounded-lg border border-line bg-card pl-1 pr-1.5"
          aria-label="Switch site"
        >
          <span className="flex h-6 w-6 items-center justify-center rounded-md bg-brand text-[10px] font-bold text-white">
            {initials(active?.name)}
          </span>
          <ChevronDown size={13} className="text-muted" />
        </button>
        {menu && sites.length > 0 && (
          <div className="absolute right-0 z-40 mt-2 w-56 rounded-xl border border-line bg-card p-1.5 shadow-card">
            {sites.map((s) => (
              <button
                key={s.id}
                onClick={() => { setSiteId(s.id); setMenu(false); }}
                className="ins-tap flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-sm text-fg hover:bg-black/[0.04] dark:hover:bg-white/5"
              >
                <span className="truncate">{s.name}</span>
                {s.id === siteId && <Check size={15} className="text-brand" />}
              </button>
            ))}
          </div>
        )}
      </div>

      {/* Period selector (compact native select) */}
      <select
        value={period}
        onChange={(e) => setPeriod(e.target.value)}
        aria-label="Date period"
        className="ins-tap h-8 rounded-lg border border-line bg-card px-2 text-xs font-medium text-fg outline-none"
      >
        {PERIODS.filter((p) => MOBILE_PERIODS.includes(p.key)).map((p) => (
          <option key={p.key} value={p.key}>{p.label}</option>
        ))}
      </select>

      <ThemeToggle />

      <span className="flex h-8 w-8 items-center justify-center rounded-full bg-violet text-[11px] font-bold text-white">
        {initials(user?.name)}
      </span>
    </>
  );
}
