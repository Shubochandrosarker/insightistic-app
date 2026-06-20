"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";

const sevColor: Record<string, string> = {
  high: "text-red-400 border-red-500/40",
  medium: "text-amber-300 border-amber-500/40",
  low: "text-slate-300 border-line",
};

export default function InsightsPage() {
  const { siteId } = useDashboard();
  const { data, loading, reload } = useApi<any>(siteId ? `/sites/${siteId}/insights` : null);
  const [busy, setBusy] = useState<string | null>(null);
  const [msg, setMsg] = useState<string | null>(null);

  async function generate(type: string) {
    if (!siteId) return;
    setBusy(type); setMsg(null);
    try { await apiPost(`/sites/${siteId}/insights/generate`, { type }); reload(); }
    catch (e: any) { setMsg(e.data?.message || e.message); }
    finally { setBusy(null); }
  }

  if (!siteId) return <p className="text-slate-400">Add a site first.</p>;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm text-slate-400">Generate:</span>
        {["daily", "weekly", "monthly"].map((t) => (
          <Button key={t} variant="ghost" disabled={!!busy} onClick={() => generate(t)}>
            {busy === t ? "Working…" : t}
          </Button>
        ))}
      </div>
      {msg && <p className="text-sm text-amber-300">{msg}</p>}

      {loading ? <Spinner /> : (
        <div className="space-y-3">
          {(data?.insights || []).length === 0 && <p className="text-sm text-slate-400">No insights yet — generate one above.</p>}
          {(data?.insights || []).map((i: any) => (
            <div key={i.id} className={`rounded-xl border bg-panel p-4 ${sevColor[i.severity] || "border-line"}`}>
              <div className="flex items-center justify-between">
                <h3 className="font-medium text-white">{i.title}</h3>
                <span className="text-xs uppercase tracking-wide">{i.severity} · {i.type}</span>
              </div>
              <p className="mt-2 text-sm text-slate-300">{i.summary}</p>
              {i.recommendation && <p className="mt-2 text-sm text-slate-400"><strong className="text-slate-200">Do this:</strong> {i.recommendation}</p>}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
