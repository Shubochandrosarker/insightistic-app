"use client";
import { createContext, useCallback, useContext, useEffect, useMemo, useState, ReactNode } from "react";
import { apiGet } from "./api";
import type { Site } from "./types";

/**
 * White-label: apply an agency's primary color to the brand CSS variables so
 * brand-tinted chrome (logo mark, accents, auth buttons) reflects their brand.
 */
export function applyBrand(primary?: string | null) {
  if (typeof document === "undefined" || !primary) return;
  const root = document.documentElement;
  root.style.setProperty("--ins-brand", primary);
  root.style.setProperty("--ins-brand-2", primary);
}

export const PERIODS = [
  { key: "today", label: "Today" },
  { key: "yesterday", label: "Yesterday" },
  { key: "last_7_days", label: "7 days" },
  { key: "last_30_days", label: "30 days" },
  { key: "this_month", label: "This month" },
  { key: "last_month", label: "Last month" },
];

interface DashState {
  sites: Site[];
  siteId: number | null;
  setSiteId: (id: number) => void;
  period: string;
  setPeriod: (p: string) => void;
  /** "" for presets, or "&from=YYYY-MM-DD&to=YYYY-MM-DD" for a custom range. */
  rangeQuery: string;
  setCustomRange: (from: string, to: string) => void;
  reloadSites: () => Promise<void>;
  loading: boolean;
}

const Ctx = createContext<DashState | null>(null);

/** Stored shape for `ins_period`. A legacy value was a bare period key. */
type SavedPeriod = { key: string; from?: string; to?: string };

/** ISO dates compare correctly as strings; anything malformed is rejected. */
function validRange(from?: string, to?: string): boolean {
  return Boolean(from && to && /^\d{4}-\d{2}-\d{2}$/.test(from) && /^\d{4}-\d{2}-\d{2}$/.test(to) && from <= to);
}

export function DashboardProvider({ children }: { children: ReactNode }) {
  const [sites, setSites] = useState<Site[]>([]);
  const [siteId, setSiteIdState] = useState<number | null>(null);
  const [period, setPeriodState] = useState("last_30_days");
  const [custom, setCustom] = useState<{ from: string; to: string } | null>(null);
  const [loading, setLoading] = useState(true);

  const reloadSites = useCallback(async () => {
    const res = await apiGet("/sites");
    const list: Site[] = res.sites || [];
    setSites(list);
    setSiteIdState((cur) => {
      // A restored ins_site that this account can't see (stale selection
      // from a previous sign-in on a shared browser, or a site removed
      // since) must not stick — fall back to the first available site.
      if (cur != null && list.some((s) => s.id === cur)) return cur;
      return list[0]?.id ?? null;
    });
  }, []);

  useEffect(() => {
    const saved = localStorage.getItem("ins_period");
    if (saved) {
      try {
        const parsed = JSON.parse(saved) as SavedPeriod;
        if (parsed && typeof parsed.key === "string" && parsed.key !== "custom") {
          setPeriodState(parsed.key);
        } else if (parsed && parsed.key === "custom" && validRange(parsed.from, parsed.to)) {
          setPeriodState("custom");
          setCustom({ from: parsed.from as string, to: parsed.to as string });
        }
      } catch {
        // Legacy plain-string format (pre-custom-range).
        setPeriodState(saved);
      }
    }
    const savedSite = localStorage.getItem("ins_site");
    if (savedSite) setSiteIdState(Number(savedSite));
    reloadSites().finally(() => setLoading(false));

    // Best-effort: apply saved white-label branding (owner/admin only; ignored otherwise).
    // A failure here shouldn't block the dashboard, but it shouldn't be
    // silent either — an unbranded/wrong-branded white-label dashboard with
    // no signal anywhere is exactly the kind of bug that's hard to notice.
    apiGet("/brand-settings")
      .then((r) => applyBrand(r?.brand_settings?.primary_color))
      .catch((err) => console.warn("Failed to load brand settings; using defaults.", err));
  }, []);

  const setSiteId = useCallback((id: number) => {
    setSiteIdState(id);
    localStorage.setItem("ins_site", String(id));
  }, []);

  // Choosing a preset always clears any custom range.
  const setPeriod = useCallback((p: string) => {
    setPeriodState(p);
    setCustom(null);
    localStorage.setItem("ins_period", JSON.stringify({ key: p } satisfies SavedPeriod));
  }, []);

  // The backend silently coerces an end before the start; we keep to >= from
  // client-side so the picker can never produce a misleading range.
  const setCustomRange = useCallback((from: string, to: string) => {
    if (!validRange(from, to)) return;
    setPeriodState("custom");
    setCustom({ from, to });
    localStorage.setItem("ins_period", JSON.stringify({ key: "custom", from, to } satisfies SavedPeriod));
  }, []);

  const rangeQuery = custom ? `&from=${custom.from}&to=${custom.to}` : "";

  // Stable identities + memoized value: pages hang effects off these
  // callbacks (see lib/auth.tsx for why unstable identities are dangerous).
  const value = useMemo<DashState>(
    () => ({ sites, siteId, setSiteId, period, setPeriod, rangeQuery, setCustomRange, reloadSites, loading }),
    [sites, siteId, setSiteId, period, setPeriod, rangeQuery, setCustomRange, reloadSites, loading],
  );

  return (
    <Ctx.Provider value={value}>
      {children}
    </Ctx.Provider>
  );
}

export function useDashboard() {
  const c = useContext(Ctx);
  if (!c) throw new Error("useDashboard must be used within DashboardProvider");
  return c;
}
