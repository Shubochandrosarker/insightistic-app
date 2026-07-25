"use client";
import { useMemo, useState } from "react";
import Link from "next/link";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost, type ApiError } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { Sparkles, ArrowRight } from "lucide-react";

const SEV_TONE: Record<string, "red" | "amber" | "green" | "slate"> = {
  high: "red", medium: "amber", low: "green", info: "slate",
};

// Contextual insight types (each looks at one slice of the data) + legacy digests.
const CONTEXTUAL_TYPES = [
  "executive", "traffic", "seo", "performance", "commerce",
  "products", "customers", "content", "alerts",
];
const LEGACY_TYPES = ["weekly", "monthly"];
const GENERATE_TYPES = [...CONTEXTUAL_TYPES, ...LEGACY_TYPES];

export default function InsightsPage() {
  const { siteId, sites, period } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const { data, loading, error, reload } = useApi<any>(siteId ? `/sites/${siteId}/insights` : null);

  const [genType, setGenType] = useState("executive");
  const [busy, setBusy] = useState(false);
  const [filter, setFilter] = useState("all");
  const [notice, setNotice] = useState<string | null>(null);
  const [errMsg, setErrMsg] = useState<string | null>(null);
  const [limitMsg, setLimitMsg] = useState<string | null>(null);

  async function generate() {
    if (!siteId) return;
    setBusy(true);
    setNotice(null);
    setErrMsg(null);
    setLimitMsg(null);
    try {
      const res = await apiPost(`/sites/${siteId}/insights/generate`, { type: genType, period });
      if (res?.insight == null && res?.code === "no_data_yet") {
        setNotice(res.message || "There isn't enough data yet to generate this insight.");
      } else {
        reload();
        if (typeof res?.remaining === "number") {
          setNotice(`Insight generated — ${res.remaining} AI generation${res.remaining === 1 ? "" : "s"} left this month.`);
        }
      }
    } catch (e: any) {
      const err = e as ApiError;
      if (err.status === 402 || err.data?.code === "ai_limit_reached") {
        setLimitMsg(err.message || "You've used all AI insight generations included in your plan this month.");
      } else if (err.status === 502 || err.data?.code === "ai_unavailable") {
        setErrMsg(err.message || "The AI service is temporarily unavailable. Please try again in a few minutes.");
      } else {
        setErrMsg(err.message);
      }
    } finally {
      setBusy(false);
    }
  }

  const allInsights = useMemo(() => data?.insights || [], [data]);
  // Filter chips: "all" + every type present in the list, in a stable order.
  const filterOptions = useMemo(() => {
    const present = new Set<string>(allInsights.map((i: any) => i.type));
    const ordered = [...GENERATE_TYPES, "daily"].filter((t) => present.has(t));
    for (const t of present) if (!ordered.includes(t)) ordered.push(t);
    return ["all", ...ordered];
  }, [allInsights]);
  const insights = allInsights.filter((i: any) => filter === "all" || i.type === filter);

  if (!siteId) {
    return <EmptyState icon={<Sparkles size={20} />} title="No site connected" hint="Connect a store to generate AI insights." />;
  }

  return (
    <div>
      <PageHeader
        title="AI Insights"
        subtitle={`What changed, why it matters, and what to do next — for ${site?.name || "your store"}.`}
        right={
          <div className="flex flex-wrap items-center gap-2">
            <select
              value={genType}
              onChange={(e) => setGenType(e.target.value)}
              className="rounded-xl border border-line bg-card px-3 py-2 text-sm font-semibold text-fg"
              title="Insight type"
            >
              <optgroup label="Contextual">
                {CONTEXTUAL_TYPES.map((t) => (
                  <option key={t} value={t} className="capitalize">{t.charAt(0).toUpperCase() + t.slice(1)}</option>
                ))}
              </optgroup>
              <optgroup label="Digest">
                {LEGACY_TYPES.map((t) => (
                  <option key={t} value={t}>{t.charAt(0).toUpperCase() + t.slice(1)}</option>
                ))}
              </optgroup>
            </select>
            <button
              onClick={generate}
              disabled={busy}
              className="inline-flex items-center gap-2 rounded-xl bg-violet px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)] hover:opacity-90 disabled:opacity-50"
            >
              <Sparkles size={16} /> {busy ? "Generating…" : `Generate ${genType}`}
            </button>
          </div>
        }
      />

      {limitMsg && (
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-violet/30 bg-violet/[0.07] px-4 py-3">
          <p className="text-sm text-fg">{limitMsg}</p>
          <Link
            href="/dashboard/billing"
            className="inline-flex items-center gap-1.5 rounded-xl bg-violet px-3.5 py-1.5 text-sm font-semibold text-white hover:opacity-90"
          >
            Upgrade plan <ArrowRight size={14} />
          </Link>
        </div>
      )}
      {notice && <p className="mb-4 rounded-xl border border-line bg-card px-4 py-2.5 text-sm text-fg">{notice}</p>}
      {errMsg && <p className="mb-4 text-sm text-bad">{errMsg}</p>}
      {error && <p className="mb-4 text-sm text-bad">{error}</p>}

      <div className="mb-5 flex flex-wrap gap-2">
        {filterOptions.map((f) => (
          <button
            key={f}
            onClick={() => setFilter(f)}
            className={`rounded-full px-3.5 py-1.5 text-xs font-semibold capitalize transition ${
              filter === f ? "bg-brand text-white" : "border border-line bg-card text-muted hover:text-fg"
            }`}
          >
            {f}
          </button>
        ))}
      </div>

      {loading ? (
        <Spinner />
      ) : insights.length === 0 ? (
        <EmptyState
          icon={<Sparkles size={20} />}
          title={filter === "all" ? "No insights yet" : `No ${filter} insights yet`}
          hint="Pick a type above and generate one to get a plain-language read on your store's performance."
        />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {insights.map((i: any) => (
            <Card key={i.id}>
              <div className="mb-2 flex items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge tone="violet" className="capitalize">{i.type}</Badge>
                  {i.severity && (
                    <Badge tone={SEV_TONE[i.severity] || "slate"} dot>{i.severity} severity</Badge>
                  )}
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
