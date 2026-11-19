"use client";
import { useMemo, useState } from "react";
import Link from "next/link";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost, apiDelete, type ApiError } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { Sparkles, ArrowRight, Bookmark, BookmarkCheck, X, Undo2, ClipboardCheck, Clock } from "lucide-react";

const SEV_TONE: Record<string, "red" | "amber" | "green" | "slate"> = {
  high: "red", medium: "amber", low: "green", info: "slate",
};

// Contextual insight types (each looks at one slice of the data) + legacy digests.
const CONTEXTUAL_TYPES = [
  "executive", "traffic", "seo", "performance", "commerce",
  "products", "customers", "content", "alerts", "report",
];
const LEGACY_TYPES = ["weekly", "monthly"];
const GENERATE_TYPES = [...CONTEXTUAL_TYPES, ...LEGACY_TYPES];

/** Lifecycle views. Dismissed insights are hidden, never deleted. */
const VIEWS = [
  { key: "live", label: "Live" },
  { key: "saved", label: "Saved" },
  { key: "dismissed", label: "Dismissed" },
] as const;

type View = (typeof VIEWS)[number]["key"];

type Insight = {
  id: number;
  type: string;
  title: string;
  summary: string | null;
  ai_model?: string | null;
  sources?: string[];
  recommendation: string | null;
  severity: string | null;
  priority_score: number | null;
  status: string;
  saved: boolean;
  dismissed: boolean;
  actioned: boolean;
  stale: boolean;
  expires_at: string | null;
  created_at: string | null;
};

type TaskPayload = { title: string; markdown: string };

export default function InsightsPage() {
  const { siteId, sites, period } = useDashboard();
  const site = sites.find((s) => s.id === siteId);

  const [view, setView] = useState<View>("live");
  const { data, loading, error, reload } = useApi<{ insights: Insight[]; counts: Record<View, number> }>(
    siteId ? `/sites/${siteId}/insights?filter=${view}` : null,
  );

  const [genType, setGenType] = useState("executive");
  const [busy, setBusy] = useState(false);
  const [filter, setFilter] = useState("all");
  const [notice, setNotice] = useState<string | null>(null);
  const [errMsg, setErrMsg] = useState<string | null>(null);
  const [limitMsg, setLimitMsg] = useState<string | null>(null);
  // Which card is mid-request, so its buttons disable without freezing the page.
  const [pending, setPending] = useState<number | null>(null);
  const [task, setTask] = useState<TaskPayload | null>(null);
  const [copied, setCopied] = useState(false);

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
        setView("live");
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

  /**
   * Lifecycle mutations all follow the same shape: one call, then reload so the
   * counts and the current view agree. Reloading rather than patching local
   * state matters here — dismissing from the "live" view removes the card, and
   * a local edit would leave a stale row behind.
   */
  async function mutate(id: number, run: () => Promise<any>) {
    setPending(id);
    setErrMsg(null);
    try {
      await run();
      reload();
    } catch (e: any) {
      setErrMsg((e as ApiError).message);
    } finally {
      setPending(null);
    }
  }

  async function createTask(insight: Insight) {
    setPending(insight.id);
    setErrMsg(null);
    setCopied(false);
    try {
      const res = await apiPost(`/sites/${siteId}/insights/${insight.id}/action`);
      setTask(res.task);
      reload();
    } catch (e: any) {
      setErrMsg((e as ApiError).message);
    } finally {
      setPending(null);
    }
  }

  async function copyTask() {
    if (!task) return;
    try {
      await navigator.clipboard.writeText(task.markdown);
      setCopied(true);
    } catch {
      // Clipboard access can be denied (insecure origin, permissions policy).
      // The markdown is already on screen and selectable, so say so rather
      // than pretending the copy worked.
      setErrMsg("Couldn't copy automatically — select the text below and copy it manually.");
    }
  }

  const allInsights = useMemo<Insight[]>(() => data?.insights || [], [data]);
  // Filter chips: "all" + every type present in the list, in a stable order.
  const filterOptions = useMemo(() => {
    const present = new Set<string>(allInsights.map((i) => i.type));
    const ordered = [...GENERATE_TYPES, "daily"].filter((t) => present.has(t));
    for (const t of present) if (!ordered.includes(t)) ordered.push(t);
    return ["all", ...ordered];
  }, [allInsights]);
  const insights = allInsights.filter((i) => filter === "all" || i.type === filter);
  const counts = data?.counts;

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

      <div className="mb-4 inline-flex rounded-xl border border-line bg-card p-1">
        {VIEWS.map((v) => (
          <button
            key={v.key}
            onClick={() => setView(v.key)}
            className={`rounded-lg px-3.5 py-1.5 text-xs font-semibold transition ${
              view === v.key ? "bg-brand text-white" : "text-muted hover:text-fg"
            }`}
          >
            {v.label}
            {counts && <span className="ml-1.5 opacity-70">{counts[v.key] ?? 0}</span>}
          </button>
        ))}
      </div>

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
          title={
            view === "saved" ? "Nothing saved yet"
            : view === "dismissed" ? "Nothing dismissed"
            : filter === "all" ? "No insights yet"
            : `No ${filter} insights yet`
          }
          hint={
            view === "saved" ? "Save an insight to keep it here after it drops out of the live list."
            : view === "dismissed" ? "Dismissed insights are kept here — you can restore any of them."
            : "Pick a type above and generate one to get a plain-language read on your store's performance."
          }
        />
      ) : (
        <div className="grid gap-4 lg:grid-cols-2">
          {insights.map((i) => (
            <Card key={i.id}>
              <div className="mb-2 flex items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                  <Badge tone="violet" className="capitalize">{i.type}</Badge>
                  {i.severity && (
                    <Badge tone={SEV_TONE[i.severity] || "slate"} dot>{i.severity} severity</Badge>
                  )}
                  {i.stale && (
                    <span
                      className="inline-flex items-center gap-1 rounded-full border border-line px-2 py-0.5 text-[10px] font-semibold text-muted"
                      title="Past its freshness window — the numbers behind it may have moved."
                    >
                      <Clock size={10} /> Stale
                    </span>
                  )}
                  {i.actioned && <Badge tone="green" dot>Actioned</Badge>}
                </div>
                {i.priority_score != null && (
                  <span className="text-xs text-muted">Priority <span className="font-bold text-fg">{i.priority_score}</span>/10</span>
                )}
              </div>
              <h3 className="text-[15px] font-semibold text-fg">{i.title}</h3>
              <p className="mt-1 text-sm text-muted">{i.summary}</p>
              {i.ai_model?.startsWith("rule-based") && (
                <div className="mt-2 flex flex-wrap items-center gap-3 text-xs text-muted">
                  <span>Evidence only</span>
                  {i.sources?.includes("site_audit") && <Link className="underline" href="/dashboard/audit">Site Audit</Link>}
                  {i.sources?.includes("pagespeed") && <Link className="underline" href="/dashboard/performance">PageSpeed Insights</Link>}
                </div>
              )}
              {i.recommendation && (
                <div className="mt-3 rounded-xl border border-brand/20 bg-brand/[0.06] p-3">
                  <div className="text-[10px] font-bold uppercase tracking-wide text-brand-700">Recommended action</div>
                  <p className="mt-1 text-sm text-fg">{i.recommendation}</p>
                </div>
              )}

              <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-line pt-3">
                <button
                  onClick={() =>
                    mutate(i.id, () =>
                      i.saved
                        ? apiDelete(`/sites/${siteId}/insights/${i.id}/save`)
                        : apiPost(`/sites/${siteId}/insights/${i.id}/save`),
                    )
                  }
                  disabled={pending === i.id}
                  className="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs font-semibold text-muted transition hover:text-fg disabled:opacity-50"
                >
                  {i.saved ? <BookmarkCheck size={13} /> : <Bookmark size={13} />}
                  {i.saved ? "Saved" : "Save"}
                </button>

                {i.dismissed ? (
                  <button
                    onClick={() => mutate(i.id, () => apiDelete(`/sites/${siteId}/insights/${i.id}/dismiss`))}
                    disabled={pending === i.id}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs font-semibold text-muted transition hover:text-fg disabled:opacity-50"
                  >
                    <Undo2 size={13} /> Restore
                  </button>
                ) : (
                  <button
                    onClick={() => mutate(i.id, () => apiPost(`/sites/${siteId}/insights/${i.id}/dismiss`))}
                    disabled={pending === i.id}
                    className="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-1.5 text-xs font-semibold text-muted transition hover:text-fg disabled:opacity-50"
                  >
                    <X size={13} /> Dismiss
                  </button>
                )}

                <button
                  onClick={() => createTask(i)}
                  disabled={pending === i.id}
                  className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-card2 px-2.5 py-1.5 text-xs font-semibold text-fg transition hover:opacity-80 disabled:opacity-50"
                >
                  <ClipboardCheck size={13} /> Create task
                </button>
              </div>
            </Card>
          ))}
        </div>
      )}

      {task && (
        <div
          className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
          onClick={() => setTask(null)}
        >
          <div
            className="max-h-[80vh] w-full max-w-xl overflow-auto rounded-2xl border border-line bg-card p-5 shadow-card"
            onClick={(e) => e.stopPropagation()}
          >
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold text-fg">Task ready to paste</h3>
                <p className="mt-0.5 text-xs text-muted">
                  Markdown for Linear, Jira, Notion or wherever your team already tracks work.
                </p>
              </div>
              <button onClick={() => setTask(null)} className="text-muted hover:text-fg" aria-label="Close">
                <X size={16} />
              </button>
            </div>

            <pre className="mt-4 whitespace-pre-wrap rounded-xl border border-line bg-card2 p-3 text-xs text-fg">
              {task.markdown}
            </pre>

            <div className="mt-4 flex items-center gap-2">
              <button
                onClick={copyTask}
                className="inline-flex items-center gap-1.5 rounded-xl bg-brand px-3.5 py-2 text-sm font-semibold text-white hover:opacity-90"
              >
                <ClipboardCheck size={14} /> {copied ? "Copied" : "Copy markdown"}
              </button>
              <button onClick={() => setTask(null)} className="text-sm font-semibold text-muted hover:text-fg">
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
