"use client";
import { useEffect, useRef, useState } from "react";
import { Info, Play, Plus, Sparkles } from "lucide-react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiDelete, apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Spinner } from "@/components/ui/Spinner";
import { KpiCard } from "@/components/dashboard/KpiCard";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { AreaTrend } from "@/components/dashboard/Charts";
import { num } from "@/lib/format";

/**
 * AI Visibility overview (roadmap doc 14, Phase 11): do AI assistants mention
 * and cite this brand? The nav's Prompts / Mentions / Citations / Competitors
 * entries all live INSIDE this page as cards for now. Every metric carries
 * its exact formula (metric_definitions) as a tooltip — doc 14 acceptance:
 * "UI can explain exactly how each visibility metric is calculated".
 *
 * Writes are owner/admin server-side; like the audit RunBar we keep the
 * buttons visible for everyone and render a graceful 403 message instead of
 * hiding a feature the user might be a role bump away from.
 */

type LatestRun = {
  id: number | string;
  status?: string;
  brand_mentioned?: boolean;
  provider?: string | null;
  model?: string | null;
  checked_at?: string | null;
} | null;

type VisibilityPrompt = {
  id: number | string;
  prompt: string;
  category?: string | null;
  active?: boolean;
  last_run_at?: string | null;
  latest_run?: LatestRun;
};

type Competitor = { id: number | string; name: string; aliases?: string[] | null };
type ProviderRow = { provider: string; runs: number; mention_rate: number };
type TrendRow = { date: string; runs: number; mentions: number };

type Msg = { tone: "info" | "warn" | "bad"; text: string };
type Tone = Msg["tone"];

const inputCls =
  "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none transition placeholder:text-muted focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/15";

/** A KPI card with an info tooltip carrying the metric's exact formula. */
function InfoKpi({ label, value, info }: { label: string; value: string | number; info?: string }) {
  return (
    <div className="rounded-2xl border border-line bg-card p-4 shadow-card">
      <div className="flex items-start justify-between gap-2">
        <span className="text-xs font-medium text-muted">{label}</span>
        {info && (
          <span className="cursor-help text-muted" title={info} aria-label={`How ${label} is calculated`}>
            <Info size={13} />
          </span>
        )}
      </div>
      <div className="mt-1.5 text-[26px] font-bold leading-tight text-fg">{value}</div>
    </div>
  );
}

/**
 * metric_definitions values may arrive as a plain formula string (the API
 * today) or as {description, ...} — accept either, since the doc contract
 * only promises "an explanation per metric".
 */
function definitionText(defs: unknown, key: string): string | undefined {
  const d = (defs as Record<string, unknown> | undefined)?.[key];
  if (typeof d === "string") return d;
  if (d && typeof d === "object" && typeof (d as any).description === "string") return (d as any).description;
  return undefined;
}

const pct1 = (v: unknown) => (v == null ? "—" : `${Number(v).toFixed(1)}%`);

/** Same-day check guard — mirrors the controller's already_checked_today rule. */
function ranToday(iso?: string | null): boolean {
  if (!iso) return false;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return false;
  const now = new Date();
  return d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();
}

export default function VisibilityPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const ov = useApi<any>(siteId ? `/sites/${siteId}/ai-visibility` : null);

  const [msg, setMsg] = useState<Msg | null>(null);
  const [busyRow, setBusyRow] = useState<string | null>(null);
  const alive = useRef(true);
  useEffect(() => {
    alive.current = true;
    return () => {
      alive.current = false;
    };
  }, []);

  const flash = (tone: Tone, text: string) => setMsg({ tone, text });
  const reload = () => ov.reload();

  function runNow(p: VisibilityPrompt) {
    if (!siteId || busyRow) return;
    setBusyRow(`check-${p.id}`);
    setMsg(null);
    apiPost(`/sites/${siteId}/ai-visibility/prompts/${p.id}/check`, {})
      .then(() => {
        if (!alive.current) return;
        setBusyRow(null);
        flash("info", `Check queued for "${p.prompt}" — results refresh in a few seconds.`);
        // Runs are queued server-side; one refetch after ~8s picks up the observation.
        setTimeout(() => {
          if (alive.current) reload();
        }, 8_000);
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setBusyRow(null);
        if (e?.data?.code === "ai_visibility_quota_reached") {
          const l = e?.data?.limit || {};
          flash(
            "warn",
            `${e?.data?.message || "Your AI visibility prompt-run quota has been reached."} You've used ${num(l.used)} of ${num(l.max)} runs this month — upgrade your plan to keep checking.`,
          );
        } else if (e?.data?.code === "already_checked_today") {
          flash("warn", e?.data?.message || `"${p.prompt}" was already checked today.`);
        } else if (e?.status === 403) {
          flash("warn", "You don't have permission to run visibility checks — ask a site owner or admin.");
        } else {
          flash("bad", e?.message || "Couldn't queue the check. Try again in a moment.");
        }
      });
  }

  function removePrompt(p: VisibilityPrompt) {
    if (!siteId || busyRow) return;
    if (!globalThis.confirm(`Stop tracking "${p.prompt}"? Its run history stays on record.`)) return;
    setBusyRow(`delete-${p.id}`);
    setMsg(null);
    apiDelete(`/sites/${siteId}/ai-visibility/prompts/${p.id}`)
      .then(() => {
        if (!alive.current) return;
        setBusyRow(null);
        flash("info", `"${p.prompt}" removed from tracking — history kept.`);
        reload();
      })
      .catch((e: any) => {
        if (!alive.current) return;
        setBusyRow(null);
        if (e?.status === 403) {
          flash("warn", "You don't have permission to remove prompts — ask a site owner or admin.");
        } else {
          flash("bad", e?.message || "Couldn't remove the prompt. Try again in a moment.");
        }
      });
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<Sparkles size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to start measuring AI visibility."
      />
    );

  if (ov.loading && !ov.data) return <Spinner />;

  if (ov.error)
    return (
      <div>
        <PageHeader
          title="AI Visibility"
          subtitle={`${site?.name || ""} · How often AI assistants mention and cite your brand.`}
        />
        <ErrorState message={ov.error} onRetry={ov.reload} />
      </div>
    );

  const metrics = ov.data?.metrics || {};
  const prompts: VisibilityPrompt[] = ov.data?.prompts || [];
  const competitors: Competitor[] = ov.data?.competitors || [];
  const providers: ProviderRow[] = metrics.by_provider || [];
  const trend: TrendRow[] = metrics.trend || [];
  const defs = metrics.metric_definitions;
  // Fresh site: nothing tracked and no observations yet — the first-prompt CTA
  // instead of a wall of zeros.
  const isEmpty = prompts.length === 0 && !Number(metrics.runs_total);

  const promptColumns: Column<VisibilityPrompt>[] = [
    { key: "prompt", label: "Prompt", primary: true, render: (p) => <span className="font-medium text-fg">{p.prompt}</span> },
    {
      key: "category",
      label: "Category",
      render: (p) => (p.category ? <Badge tone="violet">{p.category}</Badge> : <span className="text-muted">—</span>),
    },
    {
      key: "brand_mentioned",
      label: "Brand mentioned",
      render: (p) =>
        p.latest_run ? (
          p.latest_run.brand_mentioned ? (
            <Badge tone="green" dot>
              Mentioned
            </Badge>
          ) : (
            <Badge tone="slate" dot>
              Not mentioned
            </Badge>
          )
        ) : (
          <span className="text-muted" title="Not checked yet">
            —
          </span>
        ),
    },
    { key: "provider", label: "Provider", render: (p) => p.latest_run?.provider || <span className="text-muted">—</span> },
    {
      key: "last_run_at",
      label: "Last run",
      align: "right",
      render: (p) => <SourceFreshness label="Ran" asOf={p.last_run_at ?? null} />,
    },
    {
      key: "actions",
      label: "Actions",
      align: "right",
      render: (p) => (
        <div className="flex items-center justify-end gap-1.5">
          <Button
            variant="ghost"
            className="px-2.5 py-1 text-xs"
            disabled={busyRow !== null || ranToday(p.last_run_at)}
            title={ranToday(p.last_run_at) ? "Already checked today" : "Ask the AI providers again now"}
            onClick={() => runNow(p)}
          >
            <Play size={12} /> Run now
          </Button>
          <Button
            variant="danger"
            className="px-2.5 py-1 text-xs"
            disabled={busyRow !== null}
            title="Stop tracking (run history kept)"
            onClick={() => removePrompt(p)}
          >
            Delete
          </Button>
        </div>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        title="AI Visibility"
        subtitle={`${site?.name || ""} · How often AI assistants mention and cite your brand.`}
      />

      {msg && (
        <p role="status" className={`mb-4 text-xs ${msg.tone === "warn" ? "text-warn" : msg.tone === "bad" ? "text-bad" : "text-muted"}`}>
          {msg.text}
        </p>
      )}

      {isEmpty ? (
        <>
          <EmptyState
            icon={<Sparkles size={20} />}
            title="Track your first AI visibility prompt"
            hint="We ask AI assistants your customer's questions and check whether they mention YOUR brand — and your competitors'."
            action={
              <AddPromptForm siteId={siteId} onAdded={reload} onMessage={flash} defaultOpen />
            }
          />
          <CompetitorsCard siteId={siteId} competitors={competitors} onChanged={reload} onMessage={flash} />
        </>
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <InfoKpi label="Mention rate" value={pct1(metrics.mention_rate)} info={definitionText(defs, "mention_rate")} />
            <InfoKpi label="Citation rate" value={pct1(metrics.citation_rate)} info={definitionText(defs, "citation_rate")} />
            <InfoKpi label="Competitor share" value={pct1(metrics.competitor_share)} info={definitionText(defs, "competitor_share")} />
            <InfoKpi
              label="Avg owned citations"
              value={metrics.avg_owned_citations_per_run != null ? String(Number(metrics.avg_owned_citations_per_run)) : "—"}
              info={definitionText(defs, "avg_owned_citations_per_run")}
            />
            <KpiCard
              label="Runs (total / failed)"
              value={`${num(Number(metrics.runs_total))} / ${num(Number(metrics.runs_failed))}`}
            />
            <KpiCard label="Prompts tracked" value={num(Number(metrics.prompts_tracked))} />
          </div>

          <div className="mt-4 grid gap-4 lg:grid-cols-3">
            <Card title="Trend" subtitle="Runs and brand mentions per day" className="lg:col-span-2">
              {trend.length > 1 ? (
                <div className="grid gap-4 sm:grid-cols-2">
                  <div>
                    <p className="mb-1 text-xs font-semibold text-muted">Runs</p>
                    <AreaTrend data={trend} dataKey="runs" height={160} />
                  </div>
                  <div>
                    <p className="mb-1 text-xs font-semibold text-muted">Brand mentions</p>
                    <AreaTrend data={trend} dataKey="mentions" color="#6C5CE7" height={160} />
                  </div>
                </div>
              ) : (
                <p className="py-8 text-center text-sm text-muted">Not enough data yet for a trend.</p>
              )}
            </Card>

            <Card title="Providers" subtitle="Per-provider results — never blended across surfaces">
              {providers.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted">No provider runs yet.</p>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {providers.map((p) => (
                    <Badge key={p.provider} tone="slate" dot>
                      {p.provider} · {num(Number(p.runs))} run{Number(p.runs) === 1 ? "" : "s"} · {pct1(p.mention_rate)} mentions
                    </Badge>
                  ))}
                </div>
              )}
            </Card>
          </div>

          <Card
            className="mt-4"
            title="Prompts"
            subtitle="The questions we ask AI assistants — each run records who got mentioned and cited."
            right={<AddPromptForm siteId={siteId} onAdded={reload} onMessage={flash} />}
          >
            <ResponsiveTable
              columns={promptColumns}
              rows={prompts}
              rowKey={(p) => p.id}
              emptyMessage="No prompts tracked yet — add your first prompt."
            />
          </Card>

          <CompetitorsCard siteId={siteId} competitors={competitors} onChanged={reload} onMessage={flash} />
        </>
      )}
    </div>
  );
}

/** Inline expanding card for tracking a new prompt (max 500 chars, optional category). */
function AddPromptForm({
  siteId,
  onAdded,
  onMessage,
  defaultOpen = false,
}: {
  siteId: number;
  onAdded: () => void;
  onMessage: (tone: Tone, text: string) => void;
  defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const [prompt, setPrompt] = useState("");
  const [category, setCategory] = useState("");
  const [busy, setBusy] = useState(false);

  function add() {
    const text = prompt.trim();
    if (!siteId || !text || busy) return;
    setBusy(true);
    apiPost(`/sites/${siteId}/ai-visibility/prompts`, {
      prompt: text,
      ...(category.trim() ? { category: category.trim() } : {}),
    })
      .then(() => {
        setPrompt("");
        setCategory("");
        setBusy(false);
        onMessage("info", "Prompt added — run it to capture the first observation.");
        onAdded();
      })
      .catch((e: any) => {
        setBusy(false);
        if (e?.data?.code === "ai_prompt_limit_reached") {
          const l = e?.data?.limit || {};
          onMessage(
            "warn",
            `${e?.data?.message || "Prompt limit reached."} You're tracking ${num(l.used)} of ${num(l.max)} prompts on your plan.`,
          );
        } else if (e?.status === 403) {
          onMessage("warn", "You don't have permission to add prompts — ask a site owner or admin.");
        } else {
          onMessage("bad", e?.message || "Couldn't add the prompt. Try again in a moment.");
        }
      });
  }

  return (
    <div data-testid="add-prompt">
      <Button variant="ghost" onClick={() => setOpen((v) => !v)} title="Track a new prompt">
        <Plus size={14} /> Add prompt
      </Button>
      {open && (
        <div className="mt-3 rounded-2xl border border-line bg-card p-4">
          <span className="mb-1.5 block text-xs font-semibold text-fg">Prompt — the question AI assistants will be asked</span>
          <textarea
            aria-label="Prompt to track"
            value={prompt}
            maxLength={500}
            rows={3}
            onChange={(e) => setPrompt(e.target.value)}
            placeholder="best WordPress booking plugin"
            className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none transition placeholder:text-muted focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/15"
          />
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <input
              aria-label="Category (optional)"
              value={category}
              maxLength={32}
              onChange={(e) => setCategory(e.target.value)}
              placeholder="Category (optional)"
              className={inputCls}
            />
            <Button onClick={add} disabled={!prompt.trim() || busy}>
              {busy ? "Adding…" : "Track prompt"}
            </Button>
            <span className="text-xs text-muted">{prompt.length}/500</span>
          </div>
        </div>
      )}
    </div>
  );
}

/** Competitor watch list: who we look for in every answer, alongside the brand. */
function CompetitorsCard({
  siteId,
  competitors,
  onChanged,
  onMessage,
}: {
  siteId: number;
  competitors: Competitor[];
  onChanged: () => void;
  onMessage: (tone: Tone, text: string) => void;
}) {
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);

  function add() {
    const n = name.trim();
    if (!siteId || !n || busy) return;
    setBusy(true);
    apiPost(`/sites/${siteId}/ai-visibility/competitors`, { name: n })
      .then(() => {
        setName("");
        setBusy(false);
        onMessage("info", `${n} added to the competitor watch list.`);
        onChanged();
      })
      .catch((e: any) => {
        setBusy(false);
        if (e?.data?.code === "competitor_exists") {
          onMessage("warn", e?.data?.message || "This competitor is already defined for the site.");
        } else if (e?.status === 403) {
          onMessage("warn", "You don't have permission to manage competitors — ask a site owner or admin.");
        } else {
          onMessage("bad", e?.message || "Couldn't add the competitor. Try again in a moment.");
        }
      });
  }

  function remove(c: Competitor) {
    if (!siteId || busy) return;
    if (!globalThis.confirm(`Remove "${c.name}" from the watch list?`)) return;
    setBusy(true);
    apiDelete(`/sites/${siteId}/ai-visibility/competitors/${c.id}`)
      .then(() => {
        setBusy(false);
        onMessage("info", `${c.name} removed — future runs stop matching them.`);
        onChanged();
      })
      .catch((e: any) => {
        setBusy(false);
        if (e?.status === 403) {
          onMessage("warn", "You don't have permission to manage competitors — ask a site owner or admin.");
        } else {
          onMessage("bad", e?.message || "Couldn't remove the competitor. Try again in a moment.");
        }
      });
  }

  return (
    <Card
      className="mt-4"
      title="Competitors"
      subtitle="Watched in every answer — the rival names we check alongside your brand."
    >
      {competitors.length === 0 ? (
        <p className="py-4 text-center text-sm text-muted">No competitors tracked yet — add the names you lose answers to.</p>
      ) : (
        <ul className="divide-y divide-line">
          {competitors.map((c) => (
            <li key={c.id} className="flex items-center gap-3 py-2.5 text-sm">
              <div className="min-w-0 flex-1">
                <p className="truncate font-medium text-fg">{c.name}</p>
                {(c.aliases?.length ?? 0) > 0 && (
                  <p className="truncate text-xs text-muted">also matched as: {(c.aliases || []).join(", ")}</p>
                )}
              </div>
              <Button
                variant="danger"
                className="px-2.5 py-1 text-xs"
                disabled={busy}
                title="Remove competitor"
                onClick={() => remove(c)}
              >
                Delete
              </Button>
            </li>
          ))}
        </ul>
      )}
      <div className="mt-3 flex flex-wrap items-center gap-2">
        <input
          aria-label="Competitor name"
          value={name}
          maxLength={120}
          onChange={(e) => setName(e.target.value)}
          placeholder="Competitor name"
          className={inputCls}
        />
        <Button variant="ghost" onClick={add} disabled={!name.trim() || busy}>
          Add competitor
        </Button>
      </div>
    </Card>
  );
}
