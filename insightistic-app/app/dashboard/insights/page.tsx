"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { Sparkles } from "lucide-react";

const SEV_TONE: Record<string, "red" | "amber" | "green" | "slate"> = {
  high: "red", medium: "amber", low: "green",
};
const FILTERS = ["all", "weekly", "monthly", "daily"];

export default function InsightsPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const { data, loading, reload } = useApi<any>(siteId ? `/sites/${siteId}/insights` : null);
  const [busy, setBusy] = useState(false);
  const [filter, setFilter] = useState("all");
  const [msg, setMsg] = useState<string | null>(null);

  async function generate() {
    if (!siteId) return;
    setBusy(true); setMsg(null);
    try { await apiPost(`/sites/${siteId}/insights/generate`, { type: "weekly" }); reload(); }
    catch (e: any) { setMsg(e.message); }
    finally { setBusy(false); }
  }

  if (!siteId) return <EmptyState icon={<Sparkles size={20} />} title="No site connected" hint="Connect a store to generate AI insights." />;

  const insights = (data?.insights || []).filter((i: any) => filter === "all" || i.type === filter);

  return (
    <div>
      <PageHeader
        title="AI Insights"
        subtitle={`What changed, why it matters, and what to do next — for ${site?.name || "your store"}.`}
        right={
          <button onClick={generate} disabled={busy}
            className="inline-flex items-center gap-2 rounded-xl bg-violet px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)] hover:opacity-90 disabled:opacity-50">
            <Sparkles size={16} /> {busy ? "Generating…" : "Generate insight"}
          </button>
        }
      />

      <div className="mb-5 flex flex-wrap gap-2">
        {FILTERS.map((f) => (
          <button key={f} onClick={() => setFilter(f)}
            className={`rounded-full px-3.5 py-1.5 text-xs font-semibold capitalize transition ${
              filter === f ? "bg-brand text-white" : "border border-line bg-card text-muted hover:text-fg"
            }`}>
            {f}
          </button>
        ))}
      </div>

      {msg && <p className="mb-4 text-sm text-warn">{msg}</p>}

      {loading ? (
        <Spinner />
      ) : insights.length === 0 ? (
        <EmptyState icon={<Sparkles size={20} />} title="No insights yet" hint="Generate one above to get a plain-language read on your store’s performance." />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {insights.map((i: any) => (
            <Card key={i.id}>
              <div className="mb-2 flex items-center justify-between gap-2">
                <div className="flex items-center gap-2">
                  <Badge tone={SEV_TONE[i.severity] || "slate"}>{i.type}</Badge>
                  <Badge tone={SEV_TONE[i.severity] || "slate"}>{i.severity} severity</Badge>
                </div>
                {i.priority_score != null && (
                  <span className="text-xs text-muted">Priority <span className="font-bold text-fg">{i.priority_score}</span>/10</span>
                )}
              </div>
              <h3 className="text-[15px] font-semibold text-fg">{i.title}</h3>
              <p className="mt-1 text-sm text-muted">{i.summary}</p>
              {i.recommendation && (
                <div className="mt-3 rounded-xl border border-brand/20 bg-brand/[0.06] p-3">
                  <div className="text-[10px] font-bold uppercase tracking-wide text-brand-700">Recommended action</div>
                  <p className="mt-1 text-sm text-fg">{i.recommendation}</p>
                </div>
              )}
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
