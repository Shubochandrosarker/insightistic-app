"use client";
import { Fragment, useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useDashboard, PERIODS } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiGet, apiPost, apiPatch, apiDelete, getToken, type ApiError } from "@/lib/api";
import type { KnowledgeBase } from "@/lib/knowledge";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { timeAgo, isSafeHref } from "@/lib/format";
import {
  Bot, Plus, Send, Square, Copy, Check, ThumbsUp, ThumbsDown, RotateCw,
  Pencil, Archive, Trash2, X, ExternalLink, BookOpen, ArrowRight, Sparkles,
  ChevronDown, Quote,
} from "lucide-react";

/* ---------------------------------- types ---------------------------------- */

interface Thread {
  id: number;
  title?: string | null;
  site_id?: number | null;
  context_json?: any;
  archived_at?: string | null;
  last_message_at?: string | null;
  created_at: string;
}

interface Citation {
  id: number;
  source_type?: string | null;
  title: string;
  url?: string | null;
  snippet?: string | null;
  score?: number | null;
  last_indexed_at?: string | null;
}

interface AnswerJson {
  direct_answer?: string | null;
  key_finding?: string | null;
  supporting_metrics?: { label: string; value: string | number }[] | null;
  why_it_matters?: string | null;
  recommended_actions?: string[] | null;
  confidence?: "high" | "medium" | "low" | null;
  data_limitations?: string | null;
  date_range?: { from: string; to: string } | null;
}

interface ChatMsg {
  id: number | string;
  role: "user" | "assistant";
  content: string;
  answer_json?: AnswerJson | null;
  status?: string | null;
  error_code?: string | null;
  created_at?: string;
  citations?: Citation[];
}

const ANALYTICS_SOURCES = [
  "executive", "traffic", "seo", "performance", "commerce",
  "products", "customers", "content", "alerts",
] as const;

const CONFIDENCE_TONE: Record<string, "green" | "amber" | "red"> = {
  high: "green", medium: "amber", low: "red",
};

/* -------------------------------- helpers --------------------------------- */

function parseAnswerJson(raw: any): AnswerJson | null {
  if (!raw) return null;
  if (typeof raw === "string") {
    try { return JSON.parse(raw); } catch { return null; }
  }
  return typeof raw === "object" ? raw : null;
}

/** Minimal rich text: paragraphs preserved via pre-wrap, **bold** spans handled. */
function RichText({ text }: { text: string }) {
  const parts = text.split(/(\*\*[^*]+\*\*)/g);
  return (
    <div className="whitespace-pre-wrap break-words text-sm leading-relaxed">
      {parts.map((p, i) =>
        p.startsWith("**") && p.endsWith("**") && p.length > 4
          ? <strong key={i} className="font-semibold">{p.slice(2, -2)}</strong>
          : <Fragment key={i}>{p}</Fragment>
      )}
    </div>
  );
}

interface SseEvent { event: string; data: any }

function parseSseBlock(block: string): SseEvent | null {
  let event = "message";
  const dataLines: string[] = [];
  for (const line of block.split("\n")) {
    if (line.startsWith("event:")) event = line.slice(6).trim();
    else if (line.startsWith("data:")) dataLines.push(line.slice(5).trimStart());
  }
  if (!dataLines.length) return event === "message" ? null : { event, data: {} };
  try {
    return { event, data: JSON.parse(dataLines.join("\n")) };
  } catch {
    return { event, data: {} };
  }
}

/* ------------------------------ small pieces ------------------------------ */

function MultiSelect({
  label, options, selected, onToggle, allLabel,
}: {
  label: string;
  options: { value: string; label: string }[];
  selected: string[];
  onToggle: (value: string) => void;
  allLabel: string;
}) {
  const detailsRef = useRef<HTMLDetailsElement>(null);

  // Native <details> elements toggle independently — two open at once
  // overlap their panels. A pointer outside this details element closes it.
  useEffect(() => {
    const el = detailsRef.current;
    if (!el) return;
    const onPointerDown = (e: PointerEvent) => {
      if (!el.contains(e.target as Node)) el.open = false;
    };
    document.addEventListener("pointerdown", onPointerDown);
    return () => document.removeEventListener("pointerdown", onPointerDown);
  }, []);

  return (
    <details ref={detailsRef} className="relative">
      <summary className="flex cursor-pointer list-none items-center gap-1.5 rounded-full border border-line bg-card2 px-3 py-1.5 text-xs font-semibold text-fg hover:border-violet/40 [&::-webkit-details-marker]:hidden">
        {label}
        <span className="text-muted">{selected.length ? selected.length : "auto"}</span>
        <ChevronDown size={12} className="text-muted" />
      </summary>
      <div className="absolute left-0 top-full z-30 mt-1.5 max-h-64 w-56 overflow-y-auto rounded-xl border border-line bg-card p-2 shadow-card">
        <p className="px-2 py-1 text-[10px] uppercase tracking-wide text-muted">{allLabel}</p>
        {options.length === 0 && <p className="px-2 py-1.5 text-xs text-muted">Nothing available yet.</p>}
        {options.map((o) => (
          <label key={o.value} className="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm text-fg hover:bg-card2">
            <input
              type="checkbox"
              checked={selected.includes(o.value)}
              onChange={() => onToggle(o.value)}
              className="h-3.5 w-3.5 accent-[#6C5CE7]"
            />
            <span className="capitalize">{o.label}</span>
          </label>
        ))}
      </div>
    </details>
  );
}

function AnswerCard({ a }: { a: AnswerJson }) {
  return (
    <div className="mt-3 space-y-3 rounded-xl border border-violet/25 bg-violet/[0.05] p-3.5">
      {a.direct_answer && (
        <div>
          <div className="text-[10px] font-bold uppercase tracking-wide text-violet">Direct answer</div>
          <p className="mt-0.5 text-sm text-fg">{a.direct_answer}</p>
        </div>
      )}
      {a.key_finding && (
        <div>
          <div className="text-[10px] font-bold uppercase tracking-wide text-violet">Key finding</div>
          <p className="mt-0.5 text-sm text-fg">{a.key_finding}</p>
        </div>
      )}
      {!!a.supporting_metrics?.length && (
        <div>
          <div className="mb-1 text-[10px] font-bold uppercase tracking-wide text-violet">Supporting metrics</div>
          <table className="w-full text-sm">
            <tbody>
              {a.supporting_metrics.map((m, i) => (
                <tr key={i} className="border-b border-line last:border-0">
                  <td className="py-1.5 pr-3 text-muted">{m.label}</td>
                  <td className="py-1.5 text-right font-semibold text-fg">{m.value}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {a.why_it_matters && (
        <div>
          <div className="text-[10px] font-bold uppercase tracking-wide text-violet">Why it matters</div>
          <p className="mt-0.5 text-sm text-fg">{a.why_it_matters}</p>
        </div>
      )}
      {!!a.recommended_actions?.length && (
        <div>
          <div className="text-[10px] font-bold uppercase tracking-wide text-violet">Recommended actions</div>
          <ul className="mt-1 space-y-1">
            {a.recommended_actions.map((r, i) => (
              <li key={i} className="flex gap-2 text-sm text-fg">
                <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-violet" />
                {r}
              </li>
            ))}
          </ul>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-2 pt-0.5">
        {a.confidence && (
          <Badge tone={CONFIDENCE_TONE[a.confidence] || "slate"} dot>{a.confidence} confidence</Badge>
        )}
        {a.date_range && (
          <span className="text-xs text-muted">{a.date_range.from} → {a.date_range.to}</span>
        )}
      </div>
      {a.data_limitations && (
        <p className="text-xs text-muted"><span className="font-semibold">Data limitations:</span> {a.data_limitations}</p>
      )}
    </div>
  );
}

/* --------------------------------- page ----------------------------------- */

export default function CopilotPage() {
  const { sites, siteId, setSiteId, period, setPeriod } = useDashboard();

  const threadsApi = useApi<{ threads: Thread[] }>("/copilot/threads");
  const suggestionsApi = useApi<{ suggestions: string[] }>("/copilot/suggestions");
  const kbsApi = useApi<{ knowledge_bases: KnowledgeBase[] }>("/knowledge-bases");

  const threads = useMemo(() => threadsApi.data?.threads || [], [threadsApi.data]);
  const kbs = useMemo(() => (kbsApi.data?.knowledge_bases || []).filter((k) => k.status !== "archived"), [kbsApi.data]);

  const [activeId, setActiveId] = useState<number | null>(null);
  const [messages, setMessages] = useState<ChatMsg[]>([]);
  const [threadLoading, setThreadLoading] = useState(false);
  const [threadError, setThreadError] = useState<string | null>(null);

  const [input, setInput] = useState("");
  const [streaming, setStreaming] = useState(false);
  const [streamText, setStreamText] = useState("");
  const [streamCitations, setStreamCitations] = useState<Citation[]>([]);
  const [streamError, setStreamError] = useState<string | null>(null);
  const [quotaMsg, setQuotaMsg] = useState<string | null>(null);
  const [remaining, setRemaining] = useState<number | null>(null);
  const abortRef = useRef<AbortController | null>(null);

  const [sourcesSel, setSourcesSel] = useState<string[]>([]);
  const [kbSel, setKbSel] = useState<string[]>([]);
  const [agent, setAgent] = useState<string | null>(null);
  const handoffRef = useRef(false);

  const [renamingId, setRenamingId] = useState<number | null>(null);
  const [renameText, setRenameText] = useState("");
  const [feedbackGiven, setFeedbackGiven] = useState<Record<string, 1 | -1>>({});
  const [copiedId, setCopiedId] = useState<string | number | null>(null);
  const [drawerCitation, setDrawerCitation] = useState<Citation | null>(null);

  const scrollRef = useRef<HTMLDivElement>(null);

  const loadThread = useCallback(async (id: number) => {
    setThreadLoading(true);
    setThreadError(null);
    try {
      const res = await apiGet(`/copilot/threads/${id}`);
      const msgs: ChatMsg[] = (res?.messages || []).map((m: any) => ({
        ...m,
        answer_json: parseAnswerJson(m.answer_json),
        citations: m.citations || [],
      }));
      setMessages(msgs);
    } catch (e: any) {
      setThreadError((e as ApiError).message);
      setMessages([]);
    } finally {
      setThreadLoading(false);
    }
  }, []);

  useEffect(() => {
    if (activeId != null) loadThread(activeId);
    else { setMessages([]); setThreadError(null); }
    setStreamError(null);
  }, [activeId, loadThread]);

  // keep the view pinned to the latest message while chatting/streaming
  useEffect(() => {
    const el = scrollRef.current;
    if (el) el.scrollTop = el.scrollHeight;
  }, [messages, streamText, streaming]);

  // abort any in-flight stream on unmount
  useEffect(() => () => abortRef.current?.abort(), []);

  /* ------------------------------ send / stream ----------------------------- */

  // Agent hand-off: /dashboard/copilot?agent=..&prompt=..&site_id=..&period=..
  // pre-fills and auto-submits the prompt once, then clears the params.
  useEffect(() => {
    if (handoffRef.current || typeof window === "undefined") return;
    const q = new URLSearchParams(window.location.search);
    const prompt = q.get("prompt");
    const agentKey = q.get("agent");
    if (!prompt && !agentKey) return;
    handoffRef.current = true;
    const sid = Number(q.get("site_id")) || null;
    const per = q.get("period");
    if (agentKey) setAgent(agentKey);
    if (sid) setSiteId(sid);
    if (per) setPeriod(per as any);
    window.history.replaceState(null, "", window.location.pathname);
    if (prompt) send(prompt, { agent: agentKey, siteId: sid, period: per });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function send(content: string, over: { agent?: string | null; siteId?: number | null; period?: string | null } = {}) {
    const useAgent = over.agent ?? agent;
    const useSite = over.siteId ?? siteId;
    const usePeriod = over.period ?? period;
    const text = content.trim();
    if (!text || streaming) return;
    setInput("");
    setStreamError(null);
    setQuotaMsg(null);

    let threadId = activeId;
    // Lazily create a thread on the first message of a new chat.
    if (threadId == null) {
      try {
        const res = await apiPost("/copilot/threads", {
          title: text.slice(0, 60),
          ...(useSite ? { site_id: useSite } : {}),
        });
        threadId = res?.thread?.id;
        if (threadId == null) throw new Error("The server didn't return a thread.");
        setActiveId(threadId);
        setMessages([]);
        threadsApi.reload();
      } catch (e: any) {
        const err = e as ApiError;
        if (err.status === 402) setQuotaMsg(err.message);
        else setStreamError(err.message);
        setInput(text);
        return;
      }
    }

    const localId = `local-${Date.now()}`;
    setMessages((m) => [...m, { id: localId, role: "user", content: text }]);
    setStreaming(true);
    setStreamText("");
    setStreamCitations([]);

    const controller = new AbortController();
    abortRef.current = controller;
    let localText = "";
    let localCitations: Citation[] = [];
    let completed = false;
    let aborted = false;

    try {
      const token = getToken();
      const headers: Record<string, string> = {
        Accept: "text/event-stream",
        "Content-Type": "application/json",
        "x-ins-csrf": "1",
      };
      if (token) headers["Authorization"] = `Bearer ${token}`;

      const body: Record<string, any> = { content: text };
      if (useSite) body.site_id = useSite;
      if (usePeriod) body.period = usePeriod;
      if (useAgent) body.agent = useAgent;
      if (sourcesSel.length) body.sources = sourcesSel;
      if (kbSel.length) body.knowledge_base_ids = kbSel.map(Number);

      const res = await fetch(`/api/copilot/threads/${threadId}/messages`, {
        method: "POST",
        headers,
        body: JSON.stringify(body),
        signal: controller.signal,
      });

      if (res.status === 402) {
        const data = await res.json().catch(() => null);
        setQuotaMsg(data?.message || "You've used all AI messages included in your plan this month.");
        setMessages((m) => m.filter((x) => x.id !== localId));
        setInput(text);
        return;
      }
      if (!res.ok || !res.body) {
        const data = await res.json().catch(() => null);
        throw new Error(data?.message || `Request failed (HTTP ${res.status})`);
      }

      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      let buf = "";

      const handle = (evt: SseEvent | null) => {
        if (!evt) return;
        if (evt.event === "delta") {
          localText += evt.data?.text || "";
          setStreamText(localText);
        } else if (evt.event === "citations") {
          localCitations = evt.data?.citations || [];
          setStreamCitations(localCitations);
        } else if (evt.event === "run.completed") {
          completed = true;
          const msg = evt.data?.message;
          if (msg) {
            setMessages((m) => [...m, {
              ...msg,
              answer_json: parseAnswerJson(msg.answer_json),
              citations: msg.citations || localCitations,
            }]);
          }
          if (typeof evt.data?.remaining_messages === "number") setRemaining(evt.data.remaining_messages);
          setStreamText("");
          setStreamCitations([]);
          threadsApi.reload();
        } else if (evt.event === "run.failed") {
          completed = true;
          setStreamError(evt.data?.message || "The AI run failed. Please try again.");
          setStreamText("");
          setStreamCitations([]);
        }
      };

      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        buf += decoder.decode(value, { stream: true });
        let idx;
        while ((idx = buf.indexOf("\n\n")) !== -1) {
          const block = buf.slice(0, idx);
          buf = buf.slice(idx + 2);
          if (block.trim()) handle(parseSseBlock(block));
        }
      }
      if (buf.trim()) handle(parseSseBlock(buf));
      if (!completed) {
        setStreamError("The stream ended before the answer completed. Please try again.");
        setStreamText("");
      }
    } catch (e: any) {
      if (e?.name === "AbortError") aborted = true;
      else setStreamError(e?.message || "Streaming failed.");
      setStreamText("");
      setStreamCitations([]);
    } finally {
      setStreaming(false);
      abortRef.current = null;
      // After a Stop, re-sync from the server so we show exactly what was saved.
      if (aborted && threadId != null) loadThread(threadId);
    }
  }

  function stop() {
    abortRef.current?.abort();
  }

  function regenerate() {
    const lastUser = [...messages].reverse().find((m) => m.role === "user");
    if (lastUser) send(lastUser.content);
  }

  /* ------------------------------ thread actions ---------------------------- */

  async function saveRename(t: Thread) {
    const title = renameText.trim();
    setRenamingId(null);
    if (!title || title === t.title) return;
    try {
      await apiPatch(`/copilot/threads/${t.id}`, { title });
      threadsApi.reload();
    } catch (e: any) {
      setThreadError((e as ApiError).message);
    }
  }

  async function toggleArchive(t: Thread) {
    try {
      await apiPatch(`/copilot/threads/${t.id}`, { archived: !t.archived_at });
      threadsApi.reload();
    } catch (e: any) {
      setThreadError((e as ApiError).message);
    }
  }

  async function removeThread(t: Thread) {
    if (!window.confirm(`Delete "${t.title || "this chat"}"? Its messages are removed permanently.`)) return;
    try {
      await apiDelete(`/copilot/threads/${t.id}`);
      if (activeId === t.id) setActiveId(null);
      threadsApi.reload();
    } catch (e: any) {
      setThreadError((e as ApiError).message);
    }
  }

  /* ------------------------------ message actions --------------------------- */

  async function copyMessage(m: ChatMsg) {
    try {
      await navigator.clipboard.writeText(m.content);
      setCopiedId(m.id);
      setTimeout(() => setCopiedId(null), 1500);
    } catch { /* clipboard unavailable — nothing to show */ }
  }

  async function giveFeedback(m: ChatMsg, rating: 1 | -1) {
    if (typeof m.id !== "number") return;
    try {
      await apiPost(`/copilot/messages/${m.id}/feedback`, { rating });
      setFeedbackGiven((f) => ({ ...f, [String(m.id)]: rating }));
    } catch (e: any) {
      setStreamError((e as ApiError).message);
    }
  }

  /* --------------------------------- render --------------------------------- */

  const site = sites.find((s) => s.id === siteId);
  const showSuggestions = activeId == null || (!threadLoading && messages.length === 0);
  const suggestions = suggestionsApi.data?.suggestions || [];
  const lastUserMsg = [...messages].reverse().find((m) => m.role === "user");

  const threadListItem = (t: Thread) => (
    <div
      key={t.id}
      className={`group rounded-xl px-3 py-2.5 transition ${
        activeId === t.id ? "bg-violet/10" : "hover:bg-card2"
      }`}
    >
      {renamingId === t.id ? (
        <input
          autoFocus
          value={renameText}
          onChange={(e) => setRenameText(e.target.value)}
          onBlur={() => saveRename(t)}
          onKeyDown={(e) => {
            if (e.key === "Enter") saveRename(t);
            if (e.key === "Escape") setRenamingId(null);
          }}
          className="w-full rounded-lg border border-violet/40 bg-card px-2 py-1 text-sm text-fg outline-none"
        />
      ) : (
        <button onClick={() => setActiveId(t.id)} className="block w-full text-left">
          <span className={`block truncate text-sm font-medium ${activeId === t.id ? "text-violet" : "text-fg"}`}>
            {t.title || "Untitled chat"}
          </span>
          <span className="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted">
            {timeAgo(t.last_message_at || t.created_at)}
            {t.archived_at && <Badge tone="slate" className="!px-1.5 !py-0 !text-[10px]">archived</Badge>}
          </span>
        </button>
      )}
      <div className="mt-1 hidden items-center gap-0.5 group-hover:flex">
        <button
          title="Rename"
          onClick={() => { setRenamingId(t.id); setRenameText(t.title || ""); }}
          className="rounded-md p-1 text-muted hover:bg-black/5 hover:text-fg dark:hover:bg-white/10"
        >
          <Pencil size={12} />
        </button>
        <button
          title={t.archived_at ? "Unarchive" : "Archive"}
          onClick={() => toggleArchive(t)}
          className="rounded-md p-1 text-muted hover:bg-black/5 hover:text-fg dark:hover:bg-white/10"
        >
          <Archive size={12} />
        </button>
        <button
          title="Delete"
          onClick={() => removeThread(t)}
          className="rounded-md p-1 text-bad hover:bg-bad/10"
        >
          <Trash2 size={12} />
        </button>
      </div>
    </div>
  );

  const citationChips = (cits: Citation[] | undefined) =>
    !!cits?.length && (
      <div className="mt-2.5 flex flex-wrap gap-1.5">
        {cits.map((c, i) => (
          <button
            key={`${c.id}-${i}`}
            onClick={() => setDrawerCitation(c)}
            className="inline-flex max-w-[220px] items-center gap-1 rounded-full border border-violet/30 bg-violet/[0.06] px-2.5 py-1 text-[11px] font-semibold text-violet hover:bg-violet/15"
          >
            <Quote size={10} className="shrink-0" />
            <span className="truncate">{c.title || "Source"}</span>
          </button>
        ))}
      </div>
    );

  return (
    <div>
      <PageHeader
        title="AI Copilot"
        subtitle="Ask questions about your analytics and your own knowledge bases — answers cite their sources."
        right={remaining != null ? (
          <span className="text-xs text-muted">{remaining} AI message{remaining === 1 ? "" : "s"} left this month</span>
        ) : undefined}
      />

      {/* Mobile thread picker */}
      <div className="mb-4 flex gap-2 lg:hidden">
        <select
          value={activeId ?? ""}
          onChange={(e) => setActiveId(e.target.value ? Number(e.target.value) : null)}
          className="min-w-0 flex-1 rounded-xl border border-line bg-card px-3 py-2 text-sm text-fg"
        >
          <option value="">New chat</option>
          {threads.map((t) => (
            <option key={t.id} value={t.id}>
              {(t.archived_at ? "[archived] " : "") + (t.title || "Untitled chat")}
            </option>
          ))}
        </select>
        <Button
          className="!bg-violet hover:!bg-violet/90 shrink-0"
          onClick={() => setActiveId(null)}
        >
          <Plus size={15} /> New
        </Button>
      </div>

      <div className="flex h-[calc(100vh-240px)] min-h-[540px] gap-4">
        {/* Desktop thread sidebar */}
        <aside className="hidden w-64 shrink-0 flex-col overflow-hidden rounded-2xl border border-line bg-card shadow-card lg:flex">
          <div className="border-b border-line p-3">
            <Button
              className="w-full !bg-violet hover:!bg-violet/90 shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)]"
              onClick={() => setActiveId(null)}
            >
              <Plus size={15} /> New chat
            </Button>
          </div>
          <div className="ins-scroll flex-1 space-y-1 overflow-y-auto p-2">
            {threadsApi.loading && !threadsApi.data ? (
              <p className="px-3 py-2 text-xs text-muted">Loading chats…</p>
            ) : threadsApi.error ? (
              <p className="px-3 py-2 text-xs text-bad">{threadsApi.error}</p>
            ) : threads.length === 0 ? (
              <p className="px-3 py-2 text-xs text-muted">No chats yet — ask your first question.</p>
            ) : (
              threads.map(threadListItem)
            )}
          </div>
        </aside>

        {/* Main chat pane */}
        <section className="flex min-w-0 flex-1 flex-col overflow-hidden rounded-2xl border border-line bg-card shadow-card">
          {/* Context bar */}
          <div className="flex flex-wrap items-center gap-2 border-b border-line px-3.5 py-2.5">
            <select
              value={siteId ?? ""}
              onChange={(e) => e.target.value && setSiteId(Number(e.target.value))}
              className="max-w-[160px] rounded-full border border-line bg-card2 px-3 py-1.5 text-xs font-semibold text-fg"
              title="Site context"
            >
              {!site && <option value="">No site</option>}
              {sites.map((s) => (
                <option key={s.id} value={s.id}>{s.name}</option>
              ))}
            </select>
            <select
              value={period}
              onChange={(e) => setPeriod(e.target.value)}
              className="rounded-full border border-line bg-card2 px-3 py-1.5 text-xs font-semibold text-fg"
              title="Period"
            >
              {PERIODS.map((p) => (
                <option key={p.key} value={p.key}>{p.label}</option>
              ))}
            </select>
            <MultiSelect
              label="Data"
              allLabel="Analytics sources (auto when none picked)"
              options={ANALYTICS_SOURCES.map((s) => ({ value: s, label: s }))}
              selected={sourcesSel}
              onToggle={(v) => setSourcesSel((sel) => sel.includes(v) ? sel.filter((x) => x !== v) : [...sel, v])}
            />
            <MultiSelect
              label="Knowledge"
              allLabel="Knowledge bases (auto when none picked)"
              options={kbs.map((k) => ({ value: String(k.id), label: k.name }))}
              selected={kbSel}
              onToggle={(v) => setKbSel((sel) => sel.includes(v) ? sel.filter((x) => x !== v) : [...sel, v])}
            />
          </div>

          {/* Messages */}
          <div ref={scrollRef} className="ins-scroll flex-1 overflow-y-auto px-4 py-4">
            {threadLoading ? (
              <p className="py-8 text-center text-sm text-muted">Loading conversation…</p>
            ) : threadError ? (
              <p className="py-8 text-center text-sm text-bad">{threadError}</p>
            ) : (
              <div className="mx-auto max-w-2xl space-y-4">
                {messages.length === 0 && !streaming && (
                  <div className="py-8 text-center">
                    <span className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-2xl bg-violet/10 text-violet">
                      <Bot size={22} />
                    </span>
                    <h3 className="text-sm font-semibold text-fg">Ask the Copilot anything about {site?.name || "your data"}</h3>
                    <p className="mx-auto mt-1 max-w-sm text-sm text-muted">
                      It reads your analytics for the selected period plus any knowledge bases you've built, and cites where each answer came from.
                    </p>
                  </div>
                )}

                {messages.map((m) => (
                  <div key={m.id} className={m.role === "user" ? "flex justify-end" : ""}>
                    {m.role === "user" ? (
                      <div className="max-w-[85%] rounded-2xl rounded-br-md bg-violet px-4 py-2.5 text-white">
                        <RichText text={m.content} />
                      </div>
                    ) : (
                      <div className="max-w-full">
                        <div className="flex items-start gap-2.5">
                          <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-violet/10 text-violet">
                            <Bot size={14} />
                          </span>
                          <div className="min-w-0 flex-1 text-fg">
                            {m.status === "failed" ? (
                              <p className="rounded-xl border border-bad/30 bg-bad/[0.06] px-3 py-2 text-sm text-bad">
                                {m.content || "This answer failed."}{m.error_code ? ` (${m.error_code})` : ""}
                              </p>
                            ) : (
                              <RichText text={m.content} />
                            )}
                            {m.answer_json && <AnswerCard a={m.answer_json} />}
                            {citationChips(m.citations)}
                            <div className="mt-2 flex items-center gap-1">
                              <button
                                title="Copy"
                                onClick={() => copyMessage(m)}
                                className="rounded-md p-1.5 text-muted hover:bg-black/5 hover:text-fg dark:hover:bg-white/10"
                              >
                                {copiedId === m.id ? <Check size={13} className="text-good" /> : <Copy size={13} />}
                              </button>
                              {typeof m.id === "number" && (
                                <>
                                  <button
                                    title="Good answer"
                                    onClick={() => giveFeedback(m, 1)}
                                    className={`rounded-md p-1.5 hover:bg-black/5 dark:hover:bg-white/10 ${feedbackGiven[String(m.id)] === 1 ? "text-good" : "text-muted hover:text-fg"}`}
                                  >
                                    <ThumbsUp size={13} />
                                  </button>
                                  <button
                                    title="Bad answer"
                                    onClick={() => giveFeedback(m, -1)}
                                    className={`rounded-md p-1.5 hover:bg-black/5 dark:hover:bg-white/10 ${feedbackGiven[String(m.id)] === -1 ? "text-bad" : "text-muted hover:text-fg"}`}
                                  >
                                    <ThumbsDown size={13} />
                                  </button>
                                </>
                              )}
                            </div>
                          </div>
                        </div>
                      </div>
                    )}
                  </div>
                ))}

                {/* Live streaming answer */}
                {streaming && (
                  <div className="flex items-start gap-2.5">
                    <span className="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-violet/10 text-violet">
                      <Bot size={14} className="animate-pulse" />
                    </span>
                    <div className="min-w-0 flex-1 text-fg">
                      {streamText ? (
                        <RichText text={streamText} />
                      ) : (
                        <p className="text-sm text-muted">Thinking…</p>
                      )}
                      {citationChips(streamCitations)}
                    </div>
                  </div>
                )}

                {streamError && (
                  <p className="rounded-xl border border-bad/30 bg-bad/[0.06] px-3 py-2 text-sm text-bad">{streamError}</p>
                )}

                {/* Suggested questions */}
                {showSuggestions && !streaming && suggestions.length > 0 && (
                  <div className="flex flex-wrap justify-center gap-2 pt-2">
                    {suggestions.map((s, i) => (
                      <button
                        key={i}
                        onClick={() => send(s)}
                        className="inline-flex items-center gap-1.5 rounded-full border border-violet/25 bg-violet/[0.05] px-3.5 py-2 text-left text-xs font-medium text-fg hover:border-violet/50 hover:bg-violet/10"
                      >
                        <Sparkles size={12} className="shrink-0 text-violet" /> {s}
                      </button>
                    ))}
                  </div>
                )}
              </div>
            )}
          </div>

          {/* Quota banner */}
          {quotaMsg && (
            <div className="mx-4 mb-2 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-violet/30 bg-violet/[0.07] px-3.5 py-2.5">
              <p className="text-sm text-fg">{quotaMsg}</p>
              <Link
                href="/dashboard/billing"
                className="inline-flex items-center gap-1.5 rounded-xl bg-violet px-3.5 py-1.5 text-sm font-semibold text-white hover:opacity-90"
              >
                Upgrade plan <ArrowRight size={14} />
              </Link>
            </div>
          )}

          {/* Composer */}
          <div className="border-t border-line p-3">
            <div className="flex items-end gap-2">
              <textarea
                value={input}
                onChange={(e) => setInput(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    send(input);
                  }
                }}
                rows={2}
                placeholder="Ask about your traffic, sales, SEO, or anything in your knowledge bases…"
                className="ins-scroll max-h-40 min-h-[44px] w-full resize-y rounded-xl border border-line bg-card2 px-3 py-2.5 text-sm text-fg outline-none transition placeholder:text-muted focus:border-violet focus:bg-card focus:ring-2 focus:ring-violet/15"
              />
              {streaming ? (
                <button
                  onClick={stop}
                  className="inline-flex h-[44px] shrink-0 items-center gap-2 rounded-xl border border-line bg-card px-4 text-sm font-semibold text-fg hover:bg-card2"
                >
                  <Square size={14} className="fill-current" /> Stop
                </button>
              ) : (
                <button
                  onClick={() => send(input)}
                  disabled={!input.trim()}
                  className="inline-flex h-[44px] shrink-0 items-center gap-2 rounded-xl bg-violet px-4 text-sm font-semibold text-white shadow-[0_8px_20px_-8px_rgba(108,92,231,0.6)] hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"
                >
                  <Send size={14} /> Send
                </button>
              )}
            </div>
            {lastUserMsg && !streaming && (
              <button
                onClick={regenerate}
                className="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-muted hover:text-violet"
              >
                <RotateCw size={12} /> Regenerate last answer
              </button>
            )}
          </div>
        </section>
      </div>

      {/* Citation drawer */}
      {drawerCitation && (
        <>
          <div className="fixed inset-0 z-40 bg-black/30" onClick={() => setDrawerCitation(null)} />
          <aside className="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l border-line bg-card shadow-card">
            <div className="flex items-start justify-between gap-3 border-b border-line p-4">
              <div className="flex items-center gap-2.5">
                <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet/10 text-violet">
                  <BookOpen size={16} />
                </span>
                <div>
                  <h3 className="text-sm font-semibold text-fg">{drawerCitation.title || "Source"}</h3>
                  <p className="text-xs text-muted capitalize">
                    {drawerCitation.source_type || "source"}
                    {drawerCitation.score != null && ` · score ${Number(drawerCitation.score).toFixed(2)}`}
                  </p>
                </div>
              </div>
              <button onClick={() => setDrawerCitation(null)} className="rounded-lg p-1.5 text-muted hover:bg-card2 hover:text-fg">
                <X size={16} />
              </button>
            </div>
            <div className="ins-scroll flex-1 overflow-y-auto p-4">
              {drawerCitation.snippet ? (
                <blockquote className="whitespace-pre-wrap rounded-xl border border-line bg-card2 p-3.5 text-sm text-fg">
                  {drawerCitation.snippet}
                </blockquote>
              ) : (
                <p className="text-sm text-muted">No snippet available for this citation.</p>
              )}
              <dl className="mt-4 space-y-2 text-sm">
                {drawerCitation.url && isSafeHref(drawerCitation.url) && (
                  <div>
                    <dt className="text-[10px] font-bold uppercase tracking-wide text-muted">Link</dt>
                    <dd>
                      <a
                        href={drawerCitation.url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 break-all text-violet hover:underline"
                      >
                        {drawerCitation.url} <ExternalLink size={12} className="shrink-0" />
                      </a>
                    </dd>
                  </div>
                )}
                <div>
                  <dt className="text-[10px] font-bold uppercase tracking-wide text-muted">Last indexed</dt>
                  <dd className="text-fg">{drawerCitation.last_indexed_at ? timeAgo(drawerCitation.last_indexed_at) : "—"}</dd>
                </div>
              </dl>
            </div>
          </aside>
        </>
      )}
    </div>
  );
}
