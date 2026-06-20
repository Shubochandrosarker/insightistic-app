"use client";
import { useState } from "react";
import { useAuth } from "@/lib/auth";
import { useDashboard, PERIODS } from "@/lib/dashboard";
import { ChevronDown, LogOut } from "lucide-react";

export function Topbar() {
  const { user, logout } = useAuth();
  const { sites, siteId, setSiteId, period, setPeriod } = useDashboard();
  const [menu, setMenu] = useState(false);

  return (
    <header className="flex flex-wrap items-center gap-3 border-b border-line bg-panel px-5 py-3">
      <select value={siteId ?? ""} onChange={(e) => setSiteId(Number(e.target.value))}
        className="rounded-lg border border-line bg-ink px-3 py-1.5 text-sm text-slate-100">
        {sites.length === 0 && <option value="">No sites</option>}
        {sites.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
      </select>

      <div className="flex gap-1">
        {PERIODS.map((p) => (
          <button key={p.key} onClick={() => setPeriod(p.key)}
            className={`rounded-lg px-2.5 py-1.5 text-xs ${period === p.key ? "bg-brand text-white" : "border border-line text-slate-300"}`}>
            {p.label}
          </button>
        ))}
      </div>

      <div className="relative ml-auto">
        <button onClick={() => setMenu((m) => !m)} className="flex items-center gap-2 text-sm text-slate-200">
          {user?.name} <ChevronDown size={14} />
        </button>
        {menu && (
          <div className="absolute right-0 mt-2 w-40 rounded-lg border border-line bg-panel p-1 shadow-lg">
            <button onClick={logout} className="flex w-full items-center gap-2 rounded px-3 py-2 text-sm text-slate-200 hover:bg-ink">
              <LogOut size={14} /> Sign out
            </button>
          </div>
        )}
      </div>
    </header>
  );
}
