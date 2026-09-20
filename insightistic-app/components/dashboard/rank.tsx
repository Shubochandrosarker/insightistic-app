"use client";
import { useState } from "react";
import { ChevronDown, ChevronUp, Plus } from "lucide-react";
import { apiPost } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { num } from "@/lib/format";

/**
 * Shared pieces for the Rank Tracker pages (roadmap doc 13): the position
 * cell with its tone ladder, the not-configured provider banner, and the
 * inline add-keywords card. Doc 13's rule lives in every label: tracker
 * positions are SERP observations, GSC impressions are a separate signal —
 * the two are never merged into one unnamed "position" metric.
 */

/** Shown by the banner and re-shown verbatim when a check 422s with rank_provider_not_configured. */
export const PROVIDER_NOT_CONNECTED_MSG =
  "SERP provider not connected — add DataForSEO credentials (DATAFORSEO_LOGIN / DATAFORSEO_PASSWORD) on the server to enable live rank checks.";

/**
 * A SERP position with its tone: 1-3 green bold, 4-10 green, 11-20 amber,
 * beyond that muted. null renders an em dash with a "not ranking" tooltip —
 * the tracker observed the keyword and did not find it, which is data, not a
 * gap.
 */
export function PositionCell({ position }: { position: number | null }) {
  if (position === null || position === undefined)
    return (
      <span className="text-muted" title="Not ranking">
        —
      </span>
    );
  const tone =
    position <= 3
      ? "text-good font-bold"
      : position <= 10
        ? "text-good"
        : position <= 20
          ? "text-warn"
          : "text-muted";
  return (
    <span className={`font-semibold ${tone}`} title={`Position ${position}`}>
      #{position}
    </span>
  );
}

/**
 * Warn-tinted strip shown when the SERP provider is not configured. Checks
 * and buttons stay visible — the write endpoints answer the same message on
 * their 422, so the UI never hides a feature the user might be one env var
 * away from.
 */
export function ProviderBanner({ configured }: { configured: boolean }) {
  if (configured) return null;
  return (
    <div role="status" className="mb-4 rounded-xl border border-warn/25 bg-warn/10 px-4 py-2.5 text-sm text-warn">
      {PROVIDER_NOT_CONNECTED_MSG}
    </div>
  );
}

const COUNTRIES = [
  { value: "", label: "Default region" },
  { value: "US", label: "United States" },
  { value: "GB", label: "United Kingdom" },
  { value: "CA", label: "Canada" },
  { value: "AU", label: "Australia" },
  { value: "DE", label: "Germany" },
  { value: "FR", label: "France" },
  { value: "NL", label: "Netherlands" },
  { value: "ES", label: "Spain" },
  { value: "IT", label: "Italy" },
  { value: "IN", label: "India" },
];

const selectCls =
  "rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none focus:border-brand";

/**
 * Inline expanding card (not a modal) for adding tracked keywords: one
 * keyword per line, optional country/device. Result reports created vs
 * skipped so bulk pastes stay legible; the tracked-keyword quota 422 carries
 * an upgrade hint instead of looking like a failure. Adding an already-known
 * (deactivated) keyword reactivates it server-side — the copy here only
 * counts what the API reported.
 */
export function AddKeywordsDialog({
  siteId,
  onAdded,
  defaultOpen = false,
}: {
  siteId: number | null;
  onAdded: () => void;
  defaultOpen?: boolean;
}) {
  const [open, setOpen] = useState(defaultOpen);
  const [input, setInput] = useState("");
  const [country, setCountry] = useState("");
  const [device, setDevice] = useState("");
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState<{ tone: "ok" | "warn" | "bad"; text: string } | null>(null);

  const keywords = input
    .split(/\r?\n/)
    .map((l) => l.trim())
    .filter(Boolean);

  async function add() {
    if (!siteId || !keywords.length || busy) return;
    setBusy(true);
    setResult(null);
    try {
      const res = await apiPost(`/sites/${siteId}/rank/keywords`, {
        keywords: keywords.map((k) => ({
          keyword: k,
          ...(country ? { country } : {}),
          ...(device ? { device } : {}),
        })),
      });
      setResult({
        tone: "ok",
        text: `Added ${num(res?.created ?? 0)} (${num(res?.skipped ?? 0)} duplicates skipped)`,
      });
      setInput("");
      onAdded();
    } catch (e: any) {
      if (e?.data?.code === "tracked_keyword_limit_reached") {
        const limit = e?.data?.limit || {};
        setResult({
          tone: "warn",
          text: `${e?.data?.message || "Keyword limit reached"} — you're using ${num(limit.used)} of ${num(limit.max)} tracked keywords on your plan. Upgrade your plan to track more keywords.`,
        });
      } else {
        setResult({ tone: "bad", text: e?.message || "Couldn't add keywords. Try again in a moment." });
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <div data-testid="add-keywords">
      <Button variant="ghost" onClick={() => setOpen((v) => !v)} title="Track new keywords">
        {open ? <ChevronUp size={14} /> : <Plus size={14} />} Add keywords
      </Button>
      {open && (
        <div className="mt-3 rounded-2xl border border-line bg-card p-4">
          <span className="mb-1.5 block text-xs font-semibold text-fg">Keywords — one per line</span>
          <textarea
            aria-label="Keywords to track"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            rows={5}
            placeholder={"best running shoes 2026\nwaterproof hiking boots"}
            className="w-full rounded-xl border border-line bg-card2 px-3 py-2 text-sm text-fg outline-none transition placeholder:text-muted focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/15"
          />
          <div className="mt-3 flex flex-wrap items-center gap-2">
            <select value={country} onChange={(e) => setCountry(e.target.value)} className={selectCls} aria-label="Country">
              {COUNTRIES.map((c) => (
                <option key={c.value} value={c.value}>
                  {c.label}
                </option>
              ))}
            </select>
            <select value={device} onChange={(e) => setDevice(e.target.value)} className={selectCls} aria-label="Device">
              <option value="">Default device</option>
              <option value="desktop">Desktop</option>
              <option value="mobile">Mobile</option>
            </select>
            <Button onClick={add} disabled={keywords.length === 0 || busy}>
              {busy ? "Adding…" : keywords.length ? `Add ${keywords.length} keyword${keywords.length === 1 ? "" : "s"}` : "Add keywords"}
            </Button>
          </div>
          {result && (
            <p
              role="status"
              className={`mt-3 text-xs ${result.tone === "ok" ? "text-good" : result.tone === "warn" ? "text-warn" : "text-bad"}`}
            >
              {result.text}
            </p>
          )}
        </div>
      )}
    </div>
  );
}
