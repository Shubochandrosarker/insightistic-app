"use client";
import { useState } from "react";
import Link from "next/link";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost, type ApiError } from "@/lib/api";
import type { KnowledgeBase, KnowledgeUsage, UsageEntry } from "@/lib/knowledge";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { SkeletonGrid } from "@/components/app-shell/SkeletonCard";
import { BookOpen, Plus, FileStack, ArrowRight } from "lucide-react";
import { num } from "@/lib/format";

interface KbListResponse {
  knowledge_bases: KnowledgeBase[];
  usage: KnowledgeUsage;
}

const KB_STATUS_TONE: Record<string, "green" | "amber" | "red" | "violet" | "slate"> = {
  active: "green",
  archived: "slate",
};

function UsageMeter({ label, entry, unit }: { label: string; entry?: UsageEntry; unit?: string }) {
  const used = entry?.used ?? 0;
  const limit = entry?.limit ?? null;
  const unlimited = limit == null || limit <= 0;
  const ratio = unlimited ? 0 : Math.min(1, used / limit);
  const full = !unlimited && used >= limit;
  return (
    <div className="rounded-2xl border border-line bg-card p-4 shadow-card">
      <div className="flex items-baseline justify-between gap-2">
        <span className="text-xs font-medium text-muted">{label}</span>
        <span className={`text-xs font-semibold ${full ? "text-bad" : "text-fg"}`}>
          {num(used)}{unit ? ` ${unit}` : ""} / {unlimited ? "∞" : `${num(limit)}${unit ? ` ${unit}` : ""}`}
        </span>
      </div>
      <div className="mt-2.5 h-1.5 overflow-hidden rounded-full bg-black/5 dark:bg-white/10">
        <div
          className={`h-full rounded-full transition-all ${full ? "bg-bad" : ratio > 0.85 ? "bg-warn" : "bg-violet"}`}
          style={{ width: unlimited ? "0%" : `${Math.round(ratio * 100)}%` }}
        />
      </div>
    </div>
  );
}

export default function KnowledgePage() {
  const { sites } = useDashboard();
  const { data, loading, error, reload } = useApi<KbListResponse>("/knowledge-bases");

  const [creating, setCreating] = useState(false);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({ name: "", description: "", site_id: "" });
  const [formError, setFormError] = useState<string | null>(null);
  const [limitMsg, setLimitMsg] = useState<string | null>(null);

  async function create() {
    setBusy(true);
    setFormError(null);
    setLimitMsg(null);
    try {
      const body: Record<string, any> = { name: form.name.trim() };
      if (form.description.trim()) body.description = form.description.trim();
      if (form.site_id) body.site_id = Number(form.site_id);
      await apiPost("/knowledge-bases", body);
      setForm({ name: "", description: "", site_id: "" });
      setCreating(false);
      reload();
    } catch (e: any) {
      const err = e as ApiError;
      if (err.status === 402) setLimitMsg(err.message || "You've reached your knowledge base limit.");
      else setFormError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const kbs = data?.knowledge_bases || [];
  const usage = data?.usage;

  return (
    <div>
      <PageHeader
        title="Knowledge"
        subtitle="Give the AI Copilot your own documents, pages, and notes to ground its answers."
        right={
          <Button
            className="!bg-violet hover:!bg-violet/90 shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)]"
            onClick={() => setCreating((c) => !c)}
          >
            <Plus size={16} /> New knowledge base
          </Button>
        }
      />

      {limitMsg && (
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-violet/30 bg-violet/[0.07] px-4 py-3">
          <p className="text-sm text-fg">{limitMsg}</p>
          <Link
            href="/dashboard/billing"
            className="inline-flex items-center gap-1.5 rounded-xl bg-violet px-3.5 py-1.5 text-sm font-semibold text-white hover:opacity-90"
          >
            Upgrade plan <ArrowRight size={14} />
          </Link>
        </div>
      )}

      {usage && (
        <div className="mb-5 grid grid-cols-2 gap-3.5 lg:grid-cols-4">
          <UsageMeter label="Sources" entry={usage.knowledge_sources} />
          <UsageMeter label="Storage" entry={usage.storage_mb} unit="MB" />
          <UsageMeter label="Indexed chunks" entry={usage.indexed_chunks} />
          <UsageMeter label="AI messages this month" entry={usage.monthly_ai_messages} />
        </div>
      )}

      {creating && (
        <Card className="mb-5" title="New knowledge base" subtitle="Name it after the product, client, or topic it covers.">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field
              label="Name"
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
              placeholder="e.g. Product docs"
            />
            <div>
              <span className="mb-1.5 block text-xs font-semibold text-fg">Site (optional)</span>
              <select
                value={form.site_id}
                onChange={(e) => setForm({ ...form, site_id: e.target.value })}
                className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg"
              >
                <option value="">All sites</option>
                {sites.map((s) => (
                  <option key={s.id} value={s.id}>{s.name}</option>
                ))}
              </select>
            </div>
            <div className="sm:col-span-2">
              <Field
                label="Description (optional)"
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                placeholder="What lives in this knowledge base?"
              />
            </div>
          </div>
          {formError && <p className="mt-3 text-sm text-bad">{formError}</p>}
          <div className="mt-4 flex gap-2">
            <Button onClick={create} disabled={busy || !form.name.trim()}>
              {busy ? "Creating…" : "Create knowledge base"}
            </Button>
            <Button variant="ghost" onClick={() => setCreating(false)}>Cancel</Button>
          </div>
        </Card>
      )}

      {error && <p className="mb-4 text-sm text-bad">{error}</p>}

      {loading && !data ? (
        <SkeletonGrid count={4} />
      ) : kbs.length === 0 && !error ? (
        <EmptyState
          icon={<BookOpen size={20} />}
          title="No knowledge bases yet"
          hint="A knowledge base is a private library of your own content — docs, help pages, policies, product sheets. The AI Copilot searches it to answer questions with your facts instead of guesses."
          action={
            <Button
              className="!bg-violet hover:!bg-violet/90 shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)]"
              onClick={() => setCreating(true)}
            >
              <Plus size={16} /> Create your first knowledge base
            </Button>
          }
        />
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {kbs.map((kb) => {
            const site = kb.site_id ? sites.find((s) => s.id === kb.site_id) : null;
            return (
              <Link
                key={kb.id}
                href={`/dashboard/knowledge/${kb.id}`}
                className="group rounded-2xl border border-line bg-card p-5 shadow-card transition hover:border-violet/40"
              >
                <div className="mb-3 flex items-start justify-between gap-2">
                  <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-violet/10 text-violet">
                    <BookOpen size={18} />
                  </span>
                  <Badge tone={KB_STATUS_TONE[kb.status] || "slate"} dot>{kb.status}</Badge>
                </div>
                <h3 className="text-[15px] font-semibold text-fg group-hover:text-violet">{kb.name}</h3>
                {kb.description && <p className="mt-1 line-clamp-2 text-sm text-muted">{kb.description}</p>}
                <div className="mt-3 flex items-center gap-3 text-xs text-muted">
                  <span className="inline-flex items-center gap-1.5">
                    <FileStack size={13} />
                    {num(kb.indexed_sources_count)}/{num(kb.sources_count)} sources indexed
                  </span>
                  {site && <span className="truncate">· {site.name}</span>}
                </div>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}
