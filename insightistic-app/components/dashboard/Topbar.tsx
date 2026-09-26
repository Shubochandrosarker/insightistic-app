"use client";
import { useEffect, useRef, useState } from "react";
import { useAuth } from "@/lib/auth";
import { useDashboard, PERIODS } from "@/lib/dashboard";
import { CalendarDays, ChevronDown, LogOut, Check } from "lucide-react";
import { ThemeToggle } from "./ThemeToggle";
import { NotificationCenter } from "@/components/app-shell/NotificationCenter";
import { initials, timeAgo } from "@/lib/format";

/** "2026-09-07" → "Sep 7", parsed by hand so no timezone can shift the day. */
function shortDate(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
  if (!m) return iso;
  const months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
  return `${months[Number(m[2]) - 1]} ${Number(m[3])}`;
}

export function Topbar() {
  const { user, logout } = useAuth();
  const { sites, siteId, setSiteId, period, setPeriod, rangeQuery, setCustomRange } = useDashboard();
  const [siteMenu, setSiteMenu] = useState(false);
  const [periodMenu, setPeriodMenu] = useState(false);
  const [customOpen, setCustomOpen] = useState(false);
  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const headerRef = useRef<HTMLElement>(null);
  const active = sites.find((s) => s.id === siteId);

  // The site and period panels are siblings anchored over the page; two open
  // menus paint over each other (later DOM wins). Opening one closes the
  // other, and a pointer outside the header dismisses both.
  useEffect(() => {
    if (!siteMenu && !periodMenu) return;
    const onPointerDown = (e: PointerEvent) => {
      if (headerRef.current && !headerRef.current.contains(e.target as Node)) {
        setSiteMenu(false);
        setPeriodMenu(false);
        setCustomOpen(false);
      }
    };
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, [siteMenu, periodMenu]);

  const preset = PERIODS.find((p) => p.key === period);
  const customMatch = /&from=(\d{4}-\d{2}-\d{2})&to=(\d{4}-\d{2}-\d{2})/.exec(rangeQuery);
  const periodLabel = customMatch
    ? `${shortDate(customMatch[1])} – ${shortDate(customMatch[2])}`
    : preset?.label ?? "Period";

  const applyCustom = () => {
    // setCustomRange ignores an inverted range; only close when it was valid.
    if (!from || !to || from > to) return;
    setCustomRange(from, to);
    setCustomOpen(false);
    setPeriodMenu(false);
  };

  const clearCustom = () => {
    setPeriod("last_30_days");
    setFrom("");
    setTo("");
    setCustomOpen(false);
    setPeriodMenu(false);
  };

  return (
    <header
      ref={headerRef}
      className="sticky top-0 z-40 flex flex-wrap items-center gap-3 border-b border-line bg-bg/85 px-5 py-3 backdrop-blur"
    >
      {/* Site selector */}
      <div className="relative">
        <button
          onClick={() => { setPeriodMenu(false); setSiteMenu((m) => !m); }}
          aria-expanded={siteMenu}
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

      {/* Period dropdown: presets + custom range */}
      <div className="relative">
        <button
          onClick={() => { setSiteMenu(false); setPeriodMenu((m) => !m); }}
          aria-label="Date period"
          aria-expanded={periodMenu}
          className="flex items-center gap-2 rounded-xl border border-line bg-card px-3 py-1.5 text-xs font-medium text-fg shadow-sm hover:text-brand"
        >
          <CalendarDays size={14} className="text-muted" />
          {periodLabel}
          <ChevronDown size={13} className="text-muted" />
        </button>

        {periodMenu && (
          <div className="absolute left-0 z-30 mt-2 w-64 rounded-xl border border-line bg-card p-1.5 shadow-card">
            {PERIODS.map((p) => (
              <button
                key={p.key}
                onClick={() => { setPeriod(p.key); setPeriodMenu(false); }}
                className="flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-sm text-fg hover:bg-black/[0.04] dark:hover:bg-white/5"
              >
                {p.label}
                {period === p.key && <Check size={15} className="text-brand" />}
              </button>
            ))}

            <div className="my-1 border-t border-line" />

            <button
              onClick={() => setCustomOpen((v) => !v)}
              aria-expanded={customOpen}
              className="flex w-full items-center justify-between rounded-lg px-2.5 py-2 text-sm font-medium text-fg hover:bg-black/[0.04] dark:hover:bg-white/5"
            >
              Custom range…
              {period === "custom" && <Check size={15} className="text-brand" />}
            </button>

            {customOpen && (
              <div className="px-2.5 pb-2 pt-1">
                <div className="flex items-center gap-2">
                  <input
                    type="date"
                    value={from}
                    max={to || undefined}
                    onChange={(e) => setFrom(e.target.value)}
                    aria-label="Range start"
                    className="w-full rounded-lg border border-line bg-bg px-2 py-1.5 text-xs text-fg outline-none focus:border-brand"
                  />
                  <span className="text-xs text-muted">–</span>
                  <input
                    type="date"
                    value={to}
                    min={from || undefined}
                    onChange={(e) => setTo(e.target.value)}
                    aria-label="Range end"
                    className="w-full rounded-lg border border-line bg-bg px-2 py-1.5 text-xs text-fg outline-none focus:border-brand"
                  />
                </div>
                <div className="mt-2 flex gap-2">
                  <button
                    onClick={applyCustom}
                    disabled={!from || !to}
                    className="flex-1 rounded-lg bg-brand px-2.5 py-1.5 text-xs font-semibold text-white disabled:cursor-not-allowed disabled:opacity-40"
                  >
                    Apply
                  </button>
                  <button
                    onClick={clearCustom}
                    className="flex-1 rounded-lg border border-line px-2.5 py-1.5 text-xs font-semibold text-muted hover:text-fg"
                  >
                    Clear
                  </button>
                </div>
              </div>
            )}
          </div>
        )}
      </div>

      <div className="ml-auto flex items-center gap-2.5">
        {active?.last_sync_at && (
          <span className="hidden items-center gap-1.5 rounded-full bg-good/12 px-2.5 py-1 text-xs font-semibold text-good sm:inline-flex">
            <span className="h-1.5 w-1.5 rounded-full bg-good" />
            Synced {timeAgo(active.last_sync_at)}
          </span>
        )}
        <NotificationCenter />
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
