"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { apiPost, apiPatch, apiDelete, apiDownload } from "@/lib/api";
import { isSafeHref } from "@/lib/format";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { FileText, Plus, Download, Clock } from "lucide-react";

const reportDate = new Intl.DateTimeFormat(undefined, { dateStyle: "medium", timeZone: "UTC" });

function formatReportDate(value: string | null): string {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.valueOf()) ? "—" : reportDate.format(date);
}

const TYPE_TONE: Record<string, "green" | "violet" | "slate"> = {
  weekly: "green", monthly: "violet", custom: "slate",
};

const EXPORTS = [
  { key: "traffic", label: "Traffic" },
  { key: "seo", label: "SEO" },
  { key: "performance", label: "Performance" },
  { key: "commerce", label: "Commerce" },
];

function SchedulesCard({ siteId }: { siteId: number }) {
  const { data, loading, reload } = useApi<any>(`/sites/${siteId}/report-schedules`);
  const [adding, setAdding] = useState(false);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState({ frequency: "weekly", recipients: "", send_day: "monday", send_time: "09:00" });

  async function create() {
    setBusy(true);
    try { await apiPost(`/sites/${siteId}/report-schedules`, form); setAdding(false); reload(); }
    finally { setBusy(false); }
  }
  async function toggle(s: any) {
    await apiPatch(`/sites/${siteId}/report-schedules/${s.id}`, { is_active: !s.is_active });
    reload();
  }
  async function remove(id: number) {
    if (!window.confirm("Delete this schedule?")) return;
    await apiDelete(`/sites/${siteId}/report-schedules/${id}`);
    reload();
  }

  const schedules = data?.schedules || [];

  return (
    <Card
      title="Scheduled delivery"
      subtitle="Send branded reports automatically by email."
      right={<Button variant="ghost" onClick={() => setAdding((a) => !a)}><Plus size={15} /> New schedule</Button>}
    >
      {adding && (
        <div className="mb-4 space-y-3 rounded-xl border border-line bg-card2 p-4">
          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <span className="mb-1.5 block text-xs font-semibold text-fg">Frequency</span>
              <select
                value={form.frequency}
                onChange={(e) => setForm({ ...form, frequency: e.target.value })}
                className="w-full rounded-xl border border-line bg-card px-3 py-2 text-sm text-fg"
              >
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
            </div>
            <Field
              label={form.frequency === "monthly" ? "Day of month (1-28)" : "Day of week"}
              value={form.send_day}
              onChange={(e) => setForm({ ...form, send_day: e.target.value })}
              placeholder={form.frequency === "monthly" ? "1" : "monday"}
            />
            <Field label="Send time (24h, HH:MM)" value={form.send_time} onChange={(e) => setForm({ ...form, send_time: e.target.value })} />
            <Field label="Recipients (comma-separated)" value={form.recipients} onChange={(e) => setForm({ ...form, recipients: e.target.value })} placeholder="you@example.com, client@example.com" />
          </div>
          <Button onClick={create} disabled={busy || !form.recipients}>{busy ? "Saving…" : "Create schedule"}</Button>
        </div>
      )}

      {loading ? (
        <Spinner />
      ) : schedules.length === 0 ? (
        <p className="text-sm text-muted">No scheduled reports yet.</p>
      ) : (
        <div className="space-y-2">
          {schedules.map((s: any) => (
            <div key={s.id} className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-card2 px-4 py-3">
              <div className="flex items-center gap-3">
                <Clock size={16} className="text-muted" />
                <div>
                  <div className="flex items-center gap-2 text-sm font-medium text-fg">
                    <span className="capitalize">{s.frequency}</span>
                    <Badge tone={s.is_active ? "green" : "slate"} dot>{s.is_active ? "Active" : "Paused"}</Badge>
                  </div>
                  <p className="text-xs text-muted">{s.recipients} · {s.send_day} at {s.send_time || "09:00"}</p>
                </div>
              </div>
              <div className="flex gap-2">
                <Button variant="ghost" onClick={() => toggle(s)}>{s.is_active ? "Pause" : "Resume"}</Button>
                <Button variant="ghost" onClick={() => remove(s.id)}>Delete</Button>
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  );
}

function ExportsCard({ siteId }: { siteId: number }) {
  const [busy, setBusy] = useState<string | null>(null);
  // Exports follow the dashboard's active period + custom range.
  const { period, rangeQuery } = useDashboard();

  async function download(key: string) {
    setBusy(key);
    try { await apiDownload(`/sites/${siteId}/exports/${key}.csv?period=${period}${rangeQuery}`, `${key}-export.csv`); }
    catch { /* apiDownload already surfaces a readable error; nothing else to do here */ }
    finally { setBusy(null); }
  }

  return (
    <Card className="mt-5" title="CSV exports" subtitle="Selected period, per data source.">
      <div className="flex flex-wrap gap-2">
        {EXPORTS.map((e) => (
          <Button key={e.key} variant="ghost" onClick={() => download(e.key)} disabled={busy === e.key}>
            <Download size={15} /> {busy === e.key ? "Downloading…" : e.label}
          </Button>
        ))}
      </div>
    </Card>
  );
}

export default function ReportsPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const { data, loading, reload } = useApi<any>(siteId ? `/sites/${siteId}/reports` : null);
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState<string | null>(null);

  async function generate() {
    if (!siteId) return;
    setBusy(true); setMsg(null);
    try { await apiPost(`/sites/${siteId}/reports/generate`, { type: "weekly" }); reload(); }
    catch (e: any) { setMsg(e.message); }
    finally { setBusy(false); }
  }
  async function send(id: number) {
    const email = window.prompt("Send this report to which email?");
    if (!email) return;
    try { await apiPost(`/reports/${id}/send-email`, { recipients: [email] }); reload(); }
    catch (e: any) { setMsg(e.message); }
  }

  if (!siteId) return <EmptyState icon={<FileText size={20} />} title="No site connected" hint="Connect a store to generate reports." />;

  const reports = data?.reports || [];

  return (
    <div>
      <PageHeader
        title="Reports"
        subtitle="Branded weekly and monthly reports — download, email, or schedule."
        right={
          <button onClick={generate} disabled={busy}
            className="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white shadow-[0_8px_20px_-8px_rgba(0,192,75,0.55)] hover:bg-brand2 disabled:opacity-50">
            <Plus size={16} /> {busy ? "Generating…" : "Generate report"}
          </button>
        }
      />
      {msg && <p className="mb-4 text-sm text-warn">{msg}</p>}

      {loading ? (
        <Spinner />
      ) : reports.length === 0 ? (
        <EmptyState icon={<FileText size={20} />} title="No reports yet" hint="Generate a branded weekly or monthly report to download or email to clients." />
      ) : (
        <div className="space-y-3">
          {reports.map((r: any) => (
            <Card key={r.id}>
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-brand/10 text-brand"><FileText size={18} /></span>
                  <div>
                    <div className="flex items-center gap-2">
                      <span className="text-sm font-semibold text-fg">{r.title || r.report_type}</span>
                      <Badge tone={TYPE_TONE[r.report_type] || "slate"}>{r.report_type}</Badge>
                    </div>
                    <div className="text-xs text-muted">{formatReportDate(r.period_start)} – {formatReportDate(r.period_end)}{r.sent_to ? ` · sent to ${r.sent_to}` : ""}</div>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {!r.sent_to && (
                    <button onClick={() => send(r.id)} className="rounded-xl border border-line bg-card px-3.5 py-1.5 text-sm font-semibold text-fg hover:bg-card2">Send</button>
                  )}
                  {r.pdf_link && isSafeHref(r.pdf_link) && (
                    <a href={r.pdf_link} target="_blank" rel="noreferrer" className="rounded-xl bg-brand px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-brand2">View PDF</a>
                  )}
                  {r.html_link && isSafeHref(r.html_link) && (
                    <a href={r.html_link} target="_blank" rel="noreferrer" className="rounded-xl border border-line bg-card px-3.5 py-1.5 text-sm font-semibold text-fg hover:bg-card2">View HTML</a>
                  )}
                  {r.html_download_link && isSafeHref(r.html_download_link) && (
                    <a href={r.html_download_link} download className="inline-flex items-center gap-1.5 rounded-xl border border-line bg-card px-3.5 py-1.5 text-sm font-semibold text-fg hover:bg-card2">
                      <Download size={14} aria-hidden="true" /> Download HTML
                    </a>
                  )}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}

      <div className="mt-5">
        <SchedulesCard siteId={siteId} />
      </div>
      <ExportsCard siteId={siteId} />
    </div>
  );
}
