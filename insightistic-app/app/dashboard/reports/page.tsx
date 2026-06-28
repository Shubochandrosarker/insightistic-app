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
import { FileText, Plus } from "lucide-react";

const TYPE_TONE: Record<string, "green" | "violet" | "slate"> = {
  weekly: "green", monthly: "violet", custom: "slate",
};

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
    try { await apiPost(`/reports/${id}/send-email`, { email }); reload(); }
    catch (e: any) { setMsg(e.message); }
  }

  if (!siteId) return <EmptyState icon={<FileText size={20} />} title="No site connected" hint="Connect a store to generate reports." />;

  const reports = data?.reports || [];

  return (
    <div>
      <PageHeader
        title="Reports"
        subtitle="Branded weekly and monthly reports — download or send by email."
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
                    <div className="text-xs text-muted">{r.period_start} → {r.period_end}{r.sent_to ? ` · sent to ${r.sent_to}` : ""}</div>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {!r.sent_to && (
                    <button onClick={() => send(r.id)} className="rounded-xl border border-line bg-card px-3.5 py-1.5 text-sm font-semibold text-fg hover:bg-card2">Send</button>
                  )}
                  {r.pdf_link && (
                    <a href={r.pdf_link} target="_blank" rel="noreferrer" className="rounded-xl bg-brand px-3.5 py-1.5 text-sm font-semibold text-white hover:bg-brand2">PDF</a>
                  )}
                  {r.html_link && (
                    <a href={r.html_link} target="_blank" rel="noreferrer" className="rounded-xl border border-line bg-card px-3.5 py-1.5 text-sm font-semibold text-fg hover:bg-card2">HTML</a>
                  )}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
