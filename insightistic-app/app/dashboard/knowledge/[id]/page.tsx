"use client";
import { useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { useApi } from "@/lib/useApi";
import { apiPost, apiPatch, apiDelete, apiUpload, type ApiError } from "@/lib/api";
import {
  type KnowledgeBase, type KnowledgeSource, type KnowledgeUsage, type RetrievalResult,
  SOURCE_STATUS_TONE, formatBytes,
} from "@/lib/knowledge";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { Spinner } from "@/components/ui/Spinner";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { timeAgo, num, isSafeHref } from "@/lib/format";
import {
  ArrowLeft, ArrowRight, BookOpen, UploadCloud, Link2, Map, PenLine, Search,
  RotateCw, RefreshCw, Ban, Trash2, FileText, ExternalLink,
} from "lucide-react";

const MAX_FILE_BYTES = 20 * 1024 * 1024; // 20 MB
const FILE_ACCEPT = ".pdf,.docx,.txt,.md,.csv,.json,.html";

type Tab = "file" | "url" | "sitemap" | "manual";

const TABS: { key: Tab; label: string; icon: any }[] = [
  { key: "file", label: "Upload file", icon: UploadCloud },
  { key: "url", label: "URL", icon: Link2 },
  { key: "sitemap", label: "Sitemap", icon: Map },
  { key: "manual", label: "Manual text", icon: PenLine },
];

function UpgradeBanner({ message }: { message: string }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-violet/30 bg-violet/[0.07] px-4 py-3">
      <p className="text-sm text-fg">{message}</p>
      <Link
        href="/dashboard/billing"
        className="inline-flex items-center gap-1.5 rounded-xl bg-violet px-3.5 py-1.5 text-sm font-semibold text-white hover:opacity-90"
      >
        Upgrade plan <ArrowRight size={14} />
      </Link>
    </div>
  );
}

function AddSourcePanel({ kbId, onAdded }: { kbId: string; onAdded: () => void }) {
  const [tab, setTab] = useState<Tab>("file");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [limitMsg, setLimitMsg] = useState<string | null>(null);
  const [ok, setOk] = useState<string | null>(null);

  // file tab
  const [file, setFile] = useState<File | null>(null);
  const [dragging, setDragging] = useState(false);
  const fileInput = useRef<HTMLInputElement>(null);
  // shared fields
  const [title, setTitle] = useState("");
  const [url, setUrl] = useState("");
  const [content, setContent] = useState("");

  function pickFile(f: File | null) {
    setError(null);
    if (f && f.size > MAX_FILE_BYTES) {
      setFile(null);
      setError(`"${f.name}" is ${formatBytes(f.size)} — larger than the 20 MB limit.`);
      return;
    }
    setFile(f);
  }

  async function submit() {
    setBusy(true);
    setError(null);
    setLimitMsg(null);
    setOk(null);
    try {
      if (tab === "file") {
        if (!file) return;
        const fd = new FormData();
        fd.append("type", "file");
        fd.append("file", file);
        if (title.trim()) fd.append("title", title.trim());
        await apiUpload(`/knowledge-bases/${kbId}/sources`, fd);
      } else if (tab === "url" || tab === "sitemap") {
        const body: Record<string, any> = { type: tab, url: url.trim() };
        if (title.trim()) body.title = title.trim();
        await apiPost(`/knowledge-bases/${kbId}/sources`, body);
      } else {
        await apiPost(`/knowledge-bases/${kbId}/sources`, {
          type: "manual",
          title: title.trim(),
          content,
        });
      }
      setFile(null);
      setTitle("");
      setUrl("");
      setContent("");
      setOk("Source added — indexing has started.");
      onAdded();
    } catch (e: any) {
      const err = e as ApiError;
      if (err.status === 402) setLimitMsg(err.message || "You've reached a knowledge limit on your plan.");
      else setError(err.message);
    } finally {
      setBusy(false);
    }
  }

  const canSubmit =
    tab === "file" ? !!file :
    tab === "url" || tab === "sitemap" ? !!url.trim() :
    !!title.trim() && !!content.trim();

  return (
    <Card title="Add source" subtitle="Files, web pages, whole sitemaps, or pasted text — everything gets chunked and indexed.">
      <div className="mb-4 flex flex-wrap gap-2">
        {TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => { setTab(t.key); setError(null); setOk(null); }}
            className={`inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition ${
              tab === t.key ? "bg-violet text-white" : "border border-line bg-card text-muted hover:text-fg"
            }`}
          >
            <t.icon size={13} /> {t.label}
          </button>
        ))}
      </div>

      {tab === "file" && (
        <div className="space-y-3">
          <div
            onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
            onDragLeave={() => setDragging(false)}
            onDrop={(e) => {
              e.preventDefault();
              setDragging(false);
              pickFile(e.dataTransfer.files?.[0] || null);
            }}
            onClick={() => fileInput.current?.click()}
            className={`flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed px-6 py-10 text-center transition ${
              dragging ? "border-violet bg-violet/[0.06]" : "border-line bg-card2 hover:border-violet/40"
            }`}
          >
            <UploadCloud size={22} className="mb-2 text-violet" />
            {file ? (
              <p className="text-sm font-semibold text-fg">{file.name} <span className="font-normal text-muted">({formatBytes(file.size)})</span></p>
            ) : (
              <>
                <p className="text-sm font-semibold text-fg">Drop a file here, or click to browse</p>
                <p className="mt-1 text-xs text-muted">PDF, DOCX, TXT, MD, CSV, JSON, or HTML — up to 20 MB.</p>
              </>
            )}
            <input
              ref={fileInput}
              type="file"
              accept={FILE_ACCEPT}
              className="hidden"
              onChange={(e) => pickFile(e.target.files?.[0] || null)}
            />
          </div>
          <Field label="Title (optional)" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Defaults to the file name" />
        </div>
      )}

      {(tab === "url" || tab === "sitemap") && (
        <div className="grid gap-3 sm:grid-cols-2">
          <Field
            label={tab === "sitemap" ? "Sitemap URL" : "Page URL"}
            value={url}
            onChange={(e) => setUrl(e.target.value)}
            placeholder={tab === "sitemap" ? "https://example.com/sitemap.xml" : "https://example.com/docs/getting-started"}
          />
          <Field label="Title (optional)" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Defaults to the page title" />
          {tab === "sitemap" && (
            <p className="text-xs text-muted sm:col-span-2">Every page listed in the sitemap will be fetched and indexed as its own source content.</p>
          )}
        </div>
      )}

      {tab === "manual" && (
        <div className="space-y-3">
          <Field label="Title" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="e.g. Refund policy summary" />
          <label className="block">
            <span className="mb-1.5 block text-xs font-semibold text-fg">Content</span>
            <textarea
              value={content}
              onChange={(e) => setContent(e.target.value)}
              rows={6}
              placeholder="Paste or write the text to index…"
              className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none transition placeholder:text-muted focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/15"
            />
          </label>
        </div>
      )}

      {error && <p className="mt-3 text-sm text-bad">{error}</p>}
      {ok && <p className="mt-3 text-sm text-good">{ok}</p>}
      {limitMsg && <div className="mt-3"><UpgradeBanner message={limitMsg} /></div>}

      <div className="mt-4">
        <Button
          className="!bg-violet hover:!bg-violet/90 shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)]"
          onClick={submit}
          disabled={busy || !canSubmit}
        >
          {busy ? "Adding…" : "Add source"}
        </Button>
      </div>
    </Card>
  );
}

function TestRetrievalCard({ kbId }: { kbId: string }) {
  const [query, setQuery] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [results, setResults] = useState<RetrievalResult[] | null>(null);

  async function run() {
    if (!query.trim()) return;
    setBusy(true);
    setError(null);
    try {
      const res = await apiPost(`/knowledge-bases/${kbId}/test-retrieval`, { query: query.trim(), limit: 5 });
      setResults(res?.results || []);
    } catch (e: any) {
      setError((e as ApiError).message);
      setResults(null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card
      title="Test retrieval"
      subtitle="See exactly which chunks Agent JOLLY would pull for a question — no AI call, just the search."
    >
      <div className="flex gap-2">
        <input
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          onKeyDown={(e) => { if (e.key === "Enter") run(); }}
          placeholder="e.g. What is our refund window?"
          className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none transition placeholder:text-muted focus:border-violet focus:bg-card focus:ring-2 focus:ring-violet/15"
        />
        <Button
          className="!bg-violet hover:!bg-violet/90 shrink-0"
          onClick={run}
          disabled={busy || !query.trim()}
        >
          <Search size={15} /> {busy ? "Searching…" : "Search"}
        </Button>
      </div>

      {error && <p className="mt-3 text-sm text-bad">{error}</p>}

      {results !== null && !error && (
        results.length === 0 ? (
          <p className="mt-4 text-sm text-muted">No matching chunks. Try different wording, or check that your sources are indexed.</p>
        ) : (
          <div className="mt-4 space-y-3">
            {results.map((r) => (
              <div key={r.chunk_id} className="rounded-xl border border-line bg-card2 p-3.5">
                <div className="mb-1.5 flex flex-wrap items-center gap-2">
                  <Badge tone="violet">score {Number(r.score).toFixed(2)}</Badge>
                  {r.heading && <span className="text-xs font-semibold text-fg">{r.heading}</span>}
                </div>
                <p className="whitespace-pre-wrap text-sm text-fg">{r.content}</p>
                <div className="mt-2 flex items-center gap-2 text-xs text-muted">
                  <FileText size={12} />
                  {r.source?.url && isSafeHref(r.source.url) ? (
                    <a href={r.source.url} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 hover:text-violet">
                      {r.source.title} <ExternalLink size={11} />
                    </a>
                  ) : (
                    <span>{r.source?.title}</span>
                  )}
                  {r.source?.last_indexed_at && <span>· indexed {timeAgo(r.source.last_indexed_at)}</span>}
                </div>
              </div>
            ))}
          </div>
        )
      )}
    </Card>
  );
}

export default function KnowledgeBaseDetailPage() {
  const { id } = useParams<{ id: string }>();
  const router = useRouter();

  const detail = useApi<{ knowledge_base: KnowledgeBase; usage: KnowledgeUsage }>(id ? `/knowledge-bases/${id}` : null);
  const sourcesApi = useApi<{ sources: KnowledgeSource[] }>(id ? `/knowledge-bases/${id}/sources` : null);

  const kb = detail.data?.knowledge_base;
  const sources = useMemo(() => sourcesApi.data?.sources || [], [sourcesApi.data]);

  const [editing, setEditing] = useState(false);
  const [editForm, setEditForm] = useState({ name: "", description: "" });
  const [actionError, setActionError] = useState<string | null>(null);
  const [rowBusy, setRowBusy] = useState<number | null>(null);

  // Poll while anything is still being ingested; stop as soon as the queue is clear.
  const hasActive = sources.some((s) => s.status === "pending" || s.status === "processing");
  const reloadSources = sourcesApi.reload;
  useEffect(() => {
    if (!hasActive) return;
    const t = setInterval(() => reloadSources(), 4000);
    return () => clearInterval(t);
  }, [hasActive, reloadSources]);

  async function saveRename() {
    if (!kb) return;
    setActionError(null);
    try {
      await apiPatch(`/knowledge-bases/${kb.id}`, {
        name: editForm.name.trim(),
        description: editForm.description.trim() || null,
      });
      setEditing(false);
      detail.reload();
    } catch (e: any) {
      setActionError((e as ApiError).message);
    }
  }

  async function toggleArchive() {
    if (!kb) return;
    const archiving = kb.status !== "archived";
    if (!window.confirm(archiving
      ? `Archive "${kb.name}"? It will stop being used by Agent JOLLY until you restore it.`
      : `Restore "${kb.name}" to active?`)) return;
    setActionError(null);
    try {
      await apiPatch(`/knowledge-bases/${kb.id}`, { status: archiving ? "archived" : "active" });
      detail.reload();
    } catch (e: any) {
      setActionError((e as ApiError).message);
    }
  }

  async function removeKb() {
    if (!kb) return;
    if (!window.confirm(`Delete "${kb.name}" and all of its sources? This cannot be undone.`)) return;
    setActionError(null);
    try {
      await apiDelete(`/knowledge-bases/${kb.id}`);
      router.push("/dashboard/knowledge");
    } catch (e: any) {
      setActionError((e as ApiError).message);
    }
  }

  async function sourceAction(s: KnowledgeSource, action: "retry" | "reindex" | "disable" | "delete") {
    if (action === "delete" && !window.confirm(`Delete source "${s.title}"? Its indexed chunks are removed too.`)) return;
    setRowBusy(s.id);
    setActionError(null);
    try {
      if (action === "delete") await apiDelete(`/knowledge-sources/${s.id}`);
      else await apiPost(`/knowledge-sources/${s.id}/${action}`);
      reloadSources();
      detail.reload();
    } catch (e: any) {
      setActionError((e as ApiError).message);
    } finally {
      setRowBusy(null);
    }
  }

  const columns: Column<KnowledgeSource>[] = [
    {
      key: "title",
      label: "Source",
      primary: true,
      render: (s) => (
        <div className="min-w-0">
          <div className="truncate font-medium text-fg">{s.title}</div>
          <div className="truncate text-xs text-muted">
            {s.source_url || (s.size_bytes != null ? formatBytes(s.size_bytes) : "")}
          </div>
        </div>
      ),
    },
    {
      key: "type",
      label: "Type",
      render: (s) => <Badge tone="slate" className="capitalize">{s.type}</Badge>,
    },
    {
      key: "status",
      label: "Status",
      render: (s) => (
        <div>
          <Badge
            tone={SOURCE_STATUS_TONE[s.status] || "slate"}
            dot
            className={s.status === "processing" ? "animate-pulse" : ""}
          >
            {s.status}
          </Badge>
          {s.status === "failed" && s.error_message && (
            <details className="mt-1">
              <summary className="cursor-pointer text-xs text-bad hover:underline">Why?</summary>
              <p className="mt-1 max-w-xs whitespace-pre-wrap text-xs text-muted">
                {s.error_code ? `[${s.error_code}] ` : ""}{s.error_message}
              </p>
            </details>
          )}
        </div>
      ),
    },
    { key: "chunk_count", label: "Chunks", align: "right", render: (s) => <span>{num(s.chunk_count)}</span> },
    { key: "last_indexed_at", label: "Indexed", render: (s) => <span className="text-muted">{timeAgo(s.last_indexed_at)}</span> },
    {
      key: "actions",
      label: "",
      align: "right",
      render: (s) => (
        <div className="flex flex-wrap items-center justify-end gap-1">
          {s.status === "failed" && (
            <button
              title="Retry"
              disabled={rowBusy === s.id}
              onClick={() => sourceAction(s, "retry")}
              className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-violet hover:bg-violet/10 disabled:opacity-50"
            >
              <RotateCw size={13} /> Retry
            </button>
          )}
          <button
            title="Re-index"
            disabled={rowBusy === s.id || s.status === "pending" || s.status === "processing"}
            onClick={() => sourceAction(s, "reindex")}
            className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-muted hover:bg-black/5 hover:text-fg disabled:opacity-50 dark:hover:bg-white/10"
          >
            <RefreshCw size={13} /> Re-index
          </button>
          {s.status !== "disabled" && (
            <button
              title="Disable"
              disabled={rowBusy === s.id}
              onClick={() => sourceAction(s, "disable")}
              className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-muted hover:bg-black/5 hover:text-fg disabled:opacity-50 dark:hover:bg-white/10"
            >
              <Ban size={13} /> Disable
            </button>
          )}
          <button
            title="Delete"
            disabled={rowBusy === s.id}
            onClick={() => sourceAction(s, "delete")}
            className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-bad hover:bg-bad/10 disabled:opacity-50"
          >
            <Trash2 size={13} /> Delete
          </button>
        </div>
      ),
    },
  ];

  if (detail.loading && !detail.data) return <Spinner label="Loading knowledge base…" />;

  if (detail.error || !kb) {
    return (
      <div>
        <Link href="/dashboard/knowledge" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg">
          <ArrowLeft size={15} /> Back to Knowledge
        </Link>
        <Card>
          <p className="text-sm text-bad">{detail.error || "Knowledge base not found."}</p>
        </Card>
      </div>
    );
  }

  return (
    <div>
      <Link href="/dashboard/knowledge" className="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg">
        <ArrowLeft size={15} /> Back to Knowledge
      </Link>

      <PageHeader
        title={kb.name}
        subtitle={
          <span className="inline-flex flex-wrap items-center gap-2">
            {kb.description || "No description."}
            <Badge tone={kb.status === "archived" ? "slate" : "green"} dot>{kb.status}</Badge>
          </span>
        }
        right={
          <div className="flex flex-wrap gap-2">
            <Button variant="ghost" onClick={() => {
              setEditing((e) => !e);
              setEditForm({ name: kb.name, description: kb.description || "" });
            }}>
              <PenLine size={14} /> Rename
            </Button>
            <Button variant="ghost" onClick={toggleArchive}>
              {kb.status === "archived" ? "Restore" : "Archive"}
            </Button>
            <Button variant="danger" onClick={removeKb}><Trash2 size={14} /> Delete</Button>
          </div>
        }
      />

      {actionError && <p className="mb-4 text-sm text-bad">{actionError}</p>}

      {editing && (
        <Card className="mb-5" title="Edit knowledge base">
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Name" value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} />
            <Field label="Description" value={editForm.description} onChange={(e) => setEditForm({ ...editForm, description: e.target.value })} />
          </div>
          <div className="mt-4 flex gap-2">
            <Button onClick={saveRename} disabled={!editForm.name.trim()}>Save</Button>
            <Button variant="ghost" onClick={() => setEditing(false)}>Cancel</Button>
          </div>
        </Card>
      )}

      <div className="space-y-5">
        <AddSourcePanel kbId={String(kb.id)} onAdded={() => { reloadSources(); detail.reload(); }} />

        <Card
          title="Sources"
          subtitle={hasActive ? "Indexing in progress — this list refreshes every few seconds." : `${num(sources.length)} source${sources.length === 1 ? "" : "s"}.`}
          right={<Button variant="ghost" onClick={() => reloadSources()}><RefreshCw size={14} /> Refresh</Button>}
        >
          {sourcesApi.error ? (
            <p className="text-sm text-bad">{sourcesApi.error}</p>
          ) : sourcesApi.loading && !sourcesApi.data ? (
            <Spinner label="Loading sources…" />
          ) : (
            <ResponsiveTable<KnowledgeSource>
              columns={columns}
              rows={sources}
              rowKey={(s) => s.id}
              empty="No sources yet — add a file, URL, sitemap, or pasted text above."
            />
          )}
        </Card>

        <TestRetrievalCard kbId={String(kb.id)} />
      </div>
    </div>
  );
}
