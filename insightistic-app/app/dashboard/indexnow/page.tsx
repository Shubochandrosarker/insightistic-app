"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { PathCell } from "@/components/dashboard/search";
import { timeAgo } from "@/lib/format";
import { Check, Copy, RefreshCcw, Send, Zap } from "lucide-react";

/**
 * IndexNow (roadmap doc 11 §URL submission UI): key status, manual URL
 * submission and the submission log. The key FILE is hosted by the WordPress
 * plugin — this page only reports what the key is, where it must live, and
 * whether the plugin has confirmed it serves it.
 */

type IndexNowStatus = {
  key: string | null;
  key_location: string | null;
  key_file_confirmed: boolean;
  last_submitted_at: string | null;
  counts: { accepted: number; failed: number; pending: number };
};

type Submission = {
  id: number;
  url: string;
  source: string;
  status: string;
  http_status: number | null;
  error: string | null;
  created_at: string;
};

/** The controller wraps status in `status`; accept a flat body too so the page survives either envelope. */
function readStatus(data: any): IndexNowStatus {
  const st = data?.status ?? data ?? {};
  return {
    key: st.key ?? null,
    key_location: st.key_location ?? null,
    key_file_confirmed: Boolean(st.key_file_confirmed),
    last_submitted_at: st.last_submitted_at ?? null,
    counts: {
      accepted: st.counts?.accepted ?? 0,
      failed: st.counts?.failed ?? 0,
      pending: st.counts?.pending ?? 0,
    },
  };
}

const STATUS_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  accepted: "green",
  pending: "amber",
  failed: "red",
  skipped_duplicate: "slate",
  skipped_invalid_host: "slate",
};

const isHttpUrl = (s: string) => {
  try {
    const u = new URL(s);
    return u.protocol === "http:" || u.protocol === "https:";
  } catch {
    return false;
  }
};

export default function IndexNowPage() {
  const { siteId } = useDashboard();

  const { data, loading, error, reload } = useApi<any>(siteId ? `/sites/${siteId}/indexnow` : null);

  const [input, setInput] = useState("");
  const [banner, setBanner] = useState<{ tone: "ok" | "warn" | "bad"; text: string } | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const lines = input.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
  const invalid = lines.filter((l) => !isHttpUrl(l));
  const urls = lines.filter(isHttpUrl);

  const status = readStatus(data);

  if (!siteId)
    return (
      <EmptyState
        icon={<Zap size={20} />}
        title="No site connected yet"
        hint="Connect a WordPress site with the Insightistic plugin to submit URLs to search engines."
      />
    );

  if (loading) return <Spinner />;

  // A failing status fetch is not "no submissions" — surface it, plus the
  // guidance that IndexNow rides on the WordPress plugin connection.
  if (error)
    return (
      <div>
        <PageHeader title="IndexNow" subtitle="Instant URL submission to participating search engines." />
        <ErrorState title="IndexNow isn't available for this site" message={error} onRetry={reload} />
        <p className="mt-4 text-sm text-muted">
          IndexNow is delivered through the Insightistic WordPress plugin. Make sure the site's plugin connection is
          active and up to date, then try again.
        </p>
      </div>
    );

  const submit = async () => {
    if (!urls.length || busy) return;
    setBusy("submit");
    setBanner(null);
    try {
      const res = await apiPost(`/sites/${siteId}/indexnow/submit`, { urls });
      const parts = [`${res.accepted} accepted`, `${res.failed} failed`];
      if (res.skipped_duplicate) parts.push(`${res.skipped_duplicate} skipped as duplicate`);
      if (res.skipped_invalid_host) parts.push(`${res.skipped_invalid_host} skipped — different host`);
      setBanner({
        tone: res.failed > 0 ? "warn" : "ok",
        text: `Submitted ${res.submitted} URL${res.submitted === 1 ? "" : "s"} — ${parts.join(", ")}.`,
      });
      setInput("");
      reload();
    } catch (e: any) {
      // Rate limits / throttling come back with the API's own message — show it as-is.
      setBanner({ tone: "bad", text: e?.message || "Submission failed. Try again in a moment." });
    } finally {
      setBusy(null);
    }
  };

  const regenerate = async () => {
    if (busy) return;
    if (!window.confirm("Old key stops working immediately. The plugin will need to publish the new key file before submissions succeed again. Continue?")) return;
    setBusy("regen");
    setBanner(null);
    try {
      await apiPost(`/sites/${siteId}/indexnow/regenerate-key`);
      setBanner({ tone: "ok", text: "New key generated — update your plugin so it publishes the new key file." });
      reload();
    } catch (e: any) {
      setBanner({ tone: "bad", text: e?.message || "Couldn't regenerate the key. Try again." });
    } finally {
      setBusy(null);
    }
  };

  const columns: Column<Submission>[] = [
    { key: "url", label: "URL", primary: true, render: (r) => <PathCell path={r.url} /> },
    {
      key: "status",
      label: "Status",
      render: (r) => <Badge tone={STATUS_TONE[r.status] ?? "slate"}>{r.status.replace(/_/g, " ")}</Badge>,
    },
    { key: "source", label: "Source", render: (r) => r.source },
    { key: "created_at", label: "When", render: (r) => <span title={r.created_at}>{timeAgo(r.created_at)}</span> },
    {
      key: "error",
      label: "Error",
      render: (r) =>
        r.error ? (
          <span className="block max-w-[22rem] truncate text-xs text-bad" title={r.error}>
            {r.error}
          </span>
        ) : (
          "—"
        ),
    },
  ];

  const c = status.counts;

  return (
    <div>
      <PageHeader title="IndexNow" subtitle="Instant URL submission to participating search engines." />

      {banner && (
        <div
          role="status"
          className={`mb-4 rounded-xl border px-4 py-3 text-sm ${
            banner.tone === "ok"
              ? "border-good/25 bg-good/10 text-good"
              : banner.tone === "warn"
                ? "border-warn/25 bg-warn/10 text-warn"
                : "border-bad/25 bg-bad/10 text-bad"
          }`}
        >
          {banner.text}
        </div>
      )}

      <div className="grid gap-5 lg:grid-cols-2">
        <Card title="Key & status">
          {status.key ? (
            <>
              <div className="flex items-start justify-between gap-3">
                <code className="min-w-0 break-all font-mono text-sm text-fg">{status.key}</code>
                <CopyButton value={status.key} />
              </div>
              {status.key_location && (
                <p className="mt-2 break-all font-mono text-xs text-muted" title={status.key_location}>
                  {status.key_location}
                </p>
              )}
              <p className="mt-3">
                {status.key_file_confirmed ? (
                  <Badge tone="green" dot>Key file active</Badge>
                ) : (
                  <Badge tone="amber" dot>Waiting for plugin to publish {status.key}.txt</Badge>
                )}
              </p>
            </>
          ) : (
            <p className="text-sm text-muted">No IndexNow key yet — it is created with your first submission.</p>
          )}

          <p className="mt-3">
            <SourceFreshness label="Last submission" asOf={status.last_submitted_at} />
          </p>

          <div className="mt-4 grid grid-cols-3 gap-3">
            <CountTile label="Accepted" value={c.accepted} tone="text-good" />
            <CountTile label="Failed" value={c.failed} tone="text-bad" />
            <CountTile label="Pending" value={c.pending} tone="text-warn" />
          </div>

          <div className="mt-5 border-t border-line pt-4">
            <p className="text-xs text-muted">
              Regenerating replaces the key everywhere at once. Only do this if the key was exposed.
            </p>
            <Button variant="danger" className="mt-2" disabled={busy === "regen"} onClick={regenerate}>
              <RefreshCcw size={14} /> {busy === "regen" ? "Regenerating…" : "Regenerate key"}
            </Button>
          </div>
        </Card>

        <Card title="Submit URLs" subtitle="One URL per line, up to 1000 per submission.">
          <span className="mb-1.5 block text-xs font-semibold text-fg">URLs to submit</span>
          <textarea
            aria-label="URLs to submit"
            value={input}
            onChange={(e) => setInput(e.target.value)}
            rows={6}
            placeholder={"https://example.com/new-post\nhttps://example.com/updated-page"}
            className="w-full rounded-xl border border-line bg-card2 px-3 py-2 font-mono text-sm text-fg outline-none transition placeholder:text-muted focus:border-brand focus:bg-card focus:ring-2 focus:ring-brand/15"
          />

          {invalid.length > 0 && (
            <p className="mt-2 rounded-lg border border-warn/25 bg-warn/10 px-3 py-2 text-xs text-warn">
              {invalid.length} line{invalid.length === 1 ? "" : "s"} skipped — not a valid http(s) URL: {invalid.join(", ")}
            </p>
          )}

          <div className="mt-3 flex items-center gap-3">
            <Button disabled={urls.length === 0 || busy === "submit"} onClick={submit}>
              <Send size={14} /> {busy === "submit" ? "Submitting…" : "Submit URLs"}
            </Button>
            {urls.length > 0 && <span className="text-xs text-muted">{urls.length} valid URL{urls.length === 1 ? "" : "s"} ready</span>}
          </div>
        </Card>
      </div>

      <Card className="mt-5" title="Recent submissions" subtitle="Last 100 submissions across manual, plugin and automatic sources.">
        <ResponsiveTable
          columns={columns}
          rows={(data?.submissions || []) as Submission[]}
          rowKey={(r) => r.id}
          empty="No submissions yet — submit a URL or let the plugin do it on publish."
        />
      </Card>
    </div>
  );
}

function CountTile({ label, value, tone }: { label: string; value: number; tone: string }) {
  return (
    <div className="rounded-xl border border-line bg-card2 px-3 py-2.5">
      <div className={`text-lg font-bold ${tone}`}>{value.toLocaleString()}</div>
      <div className="text-[11px] uppercase tracking-wide text-muted">{label}</div>
    </div>
  );
}

/** Same clipboard idiom as the licenses page — silent no-op when the clipboard is unavailable (http). */
function CopyButton({ value }: { value: string }) {
  const [copied, setCopied] = useState(false);
  return (
    <Button
      variant="soft"
      onClick={async () => {
        try {
          await navigator.clipboard.writeText(value);
          setCopied(true);
          setTimeout(() => setCopied(false), 2000);
        } catch {
          /* clipboard unavailable (http) — user can select the text */
        }
      }}
    >
      {copied ? <Check size={15} /> : <Copy size={15} />} {copied ? "Copied" : "Copy"}
    </Button>
  );
}
