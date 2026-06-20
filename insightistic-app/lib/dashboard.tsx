"use client";
import { createContext, useContext, useEffect, useState, ReactNode } from "react";
import { apiGet } from "./api";
import type { Site } from "./types";

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
  reloadSites: () => Promise<void>;
  loading: boolean;
}

const Ctx = createContext<DashState | null>(null);

export function DashboardProvider({ children }: { children: ReactNode }) {
  const [sites, setSites] = useState<Site[]>([]);
  const [siteId, setSiteIdState] = useState<number | null>(null);
  const [period, setPeriodState] = useState("last_30_days");
  const [loading, setLoading] = useState(true);

  async function reloadSites() {
    const res = await apiGet("/sites");
    const list: Site[] = res.sites || [];
    setSites(list);
    setSiteIdState((cur) => cur ?? (list[0]?.id ?? null));
  }

  useEffect(() => {
    const savedPeriod = localStorage.getItem("ins_period");
    if (savedPeriod) setPeriodState(savedPeriod);
    const savedSite = localStorage.getItem("ins_site");
    if (savedSite) setSiteIdState(Number(savedSite));
    reloadSites().finally(() => setLoading(false));
  }, []);

  const setSiteId = (id: number) => { setSiteIdState(id); localStorage.setItem("ins_site", String(id)); };
  const setPeriod = (p: string) => { setPeriodState(p); localStorage.setItem("ins_period", p); };

  return (
    <Ctx.Provider value={{ sites, siteId, setSiteId, period, setPeriod, reloadSites, loading }}>
      {children}
    </Ctx.Provider>
  );
}

export function useDashboard() {
  const c = useContext(Ctx);
  if (!c) throw new Error("useDashboard must be used within DashboardProvider");
  return c;
}
