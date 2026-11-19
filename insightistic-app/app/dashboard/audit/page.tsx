"use client";
import Link from "next/link";
import { useState, type FormEvent } from "react";
import { apiPatch } from "@/lib/api";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { RunBar, SeverityBadge, StatusChip, issueCodeLabel, urlPath, useLatestRun, type CrawlRun, type CrawlBudget } from "@/components/dashboard/audit";
import { num, timeAgo } from "@/lib/format";
import { FileSearch } from "lucide-react";

/**
 * Site Audit overview (roadmap doc 12): the crawl health score — never
 * mysterious, always with its per-category breakdown and weights — the run
 * bar, and the last 20 crawl runs. A site that has never been crawled gets
 * the first-crawl CTA instead of empty zeros.
 */

type BreakdownEntry = { category: string; open_issues: number; penalty: number; weight: number };

function humanCategory(raw: string): string {
  return raw.replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function scoreToneCls(score: number): string {
  return score >= 90 ? "text-good" : score >= 70 ? "text-fg" : score >= 50 ? "text-warn" : "text-bad";
}

function CrawlAllocation({ siteId, budget, onSaved }: {
  siteId: number;
  budget: CrawlBudget;
  onSaved: (budget: CrawlBudget) => void;
}) {
  const [value, setValue] = useState(String(budget.project.limit ?? ""));
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    setBusy(true);
    try {
      const response = await apiPatch("/sites/" + siteId + "/crawl/settings", {
        monthly_page_limit: value.trim() === "" ? null : Number(value),
      });
      onSaved(response.budget);
      setNotice("Project limit saved.");
    } catch (error) {
      setNotice(error instanceof Error ? error.message : "Could not save the project limit.");
    } finally {
      setBusy(false);
    }
  }

  return (
    <form onSubmit={save} className="mt-5 border-t border-line pt-4">
      <div className="flex flex-wrap items-end gap-3">
        <Field label="Project crawl limit per month" type="number" min={0} max={budget.workspace.limit} step={1}
          value={value} onChange={(event) => setValue(event.target.value)} placeholder="Use workspace limit" />
        <Button type="submit" disabled={busy}>{busy ? "Saving…" : "Save project limit"}</Button>
      </div>
      <p className="mt-2 text-xs text-muted">Leave blank to use the workspace allowance. Set 0 to pause crawls for this project. Changes apply to future runs.</p>
      {notice && <p role="status" className="mt-2 text-sm text-fg">{notice}</p>}
    </form>
  );
}

export default function AuditOverviewPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);

  const runs = useLatestRun(siteId);
  // Health is computed from the latest run — don't ask for it until one exists.
  const health = useApi<any>(
    siteId && !runs.loading && !runs.error && runs.runs.length > 0 ? `/sites/${siteId}/crawl/health` : null,
  );

  function refreshAll() {
    runs.reload();
    health.reload();
  }

  if (!siteId)
    return (
      <EmptyState
        icon={<FileSearch size={20} />}
        title="No website yet"
        hint="Add a website to audit it here — any platform works, no plugin needed."
      />
    );

  // A failed runs fetch must not look like "never crawled".
  if (runs.error)
    return (
      <div>
        <PageHeader title="Site Audit" subtitle={`${site?.name || ""} · crawler health, pages, links and issues`} />
        <ErrorState message={runs.error} onRetry={runs.reload} />
      </div>
    );

  const budget = runs.budget;
  const notCrawled = !runs.loading && runs.runs.length === 0;
  const breakdown: BreakdownEntry[] = health.data?.breakdown
    ? Object.entries(health.data.breakdown as Record<string, any>).map(([category, v]: [string, any]) => ({
        category,
        open_issues: Number(v?.open_issues ?? 0),
        penalty: Number(v?.penalty ?? 0),
        weight: Number(v?.weight ?? 0),
      }))
    : [];
  // Heaviest categories first — that's the reading order of "what counts most".
  breakdown.sort((a, b) => b.weight - a.weight);
  const score = health.data?.score;
  const crawlPending = health.data?.pending === true || runs.latestRun?.status === "pending" || runs.latestRun?.status === "running";

  const runColumns: Column<CrawlRun>[] = [
    { key: "status", label: "Status", render: (r) => <StatusChip status={r.status} /> },
    { key: "trigger", label: "Trigger", render: (r) => (r.trigger === "schedule" ? "Scheduled" : "Manual") },
    { key: "pages_crawled", label: "Crawled", align: "right", render: (r) => num(r.pages_crawled ?? undefined) },
    { key: "pages_discovered", label: "Discovered", align: "right", render: (r) => num(r.pages_discovered ?? undefined) },
    { key: "issues_found", label: "Issues", align: "right", render: (r) => num(r.issues_found ?? undefined) },
    { key: "when", label: "When", align: "right", render: (r) => timeAgo(r.started_at) },
  ];

  return (
    <div>
      <PageHeader title="Site Audit" subtitle={`${site?.name || ""} · crawler health, pages, links and issues`} />

      {budget && (
        <Card title="Monthly crawl allowance" className="mb-4">
          <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              ["Available for this project", budget.project.remaining],
              ["Pages crawled across workspace", budget.workspace.used],
              ["Pages crawled for this project", budget.project.used],
              ["Available across workspace", budget.workspace.remaining],
            ].map(([label, value]) => (
              <div key={label}>
                <dt className="text-sm text-muted">{label}</dt>
                <dd className="mt-1 text-xl font-semibold text-fg">{num(Number(value))}</dd>
              </div>
            ))}
          </dl>
          <p className="mt-3 text-sm text-muted">
            Workspace limit {num(budget.workspace.limit)} pages per month. Project limit {budget.project.limit == null ? "shared with workspace" : num(budget.project.limit)}.
          </p>
          <p className="mt-2 text-sm text-muted">
            {num(budget.workspace.reserved)} pages reserved for active crawls, including {num(budget.project.reserved)} on this project.
            {" "}Next run can crawl up to {num(budget.max_next_run)} pages. Resets {budget.resets_at}.
          </p>
          {runs.canManage && <CrawlAllocation key={siteId} siteId={siteId} budget={budget} onSaved={runs.setBudget} />}
        </Card>
      )}

      {runs.loading ? (
        <Spinner />
      ) : notCrawled ? (
        <EmptyState
          icon={<FileSearch size={20} />}
          title="Run your first crawl"
          hint="Insightistic hasn't crawled this site yet. A crawl maps your pages, checks every response, and collects broken links and technical SEO issues."
          action={<RunBar key={siteId} siteId={siteId} run={null} onSettled={refreshAll} onBudget={runs.setBudget} onProgress={runs.reportProgress} compact />}
        />
      ) : (
        <>
          <Card title="Crawler" right={<SourceFreshness label="Last run" asOf={runs.latestRun?.started_at ?? null} />}>
            <RunBar key={siteId} siteId={siteId} run={runs.latestRun} onSettled={refreshAll} onBudget={runs.setBudget} onProgress={runs.reportProgress} />
          </Card>

          <div className="mt-4 grid gap-4 lg:grid-cols-5">
            {/* Doc 12: never a mysterious single score — always the breakdown beside it. */}
            <Card className="lg:col-span-2" title="Health score">
              {health.loading && !health.data ? (
                <Spinner />
              ) : health.error ? (
                <ErrorState message={health.error} onRetry={health.reload} />
              ) : (
                <>
                  {crawlPending ? (
                    <div className="rounded-xl border border-line bg-card2 px-4 py-4 text-sm text-warn">
                      Crawl in progress — the health score will be calculated after the run completes.
                    </div>
                  ) : (
                    <div className="flex items-baseline gap-2">
                      <span className={`text-5xl font-bold tracking-tight ${score != null ? scoreToneCls(Number(score)) : "text-muted"}`}>
                        {score != null ? Math.round(Number(score)) : "—"}
                      </span>
                      <span className="text-sm text-muted">/ 100</span>
                    </div>
                  )}
                  <div className="mt-4 space-y-3">
                    {crawlPending && <p className="text-sm text-muted">Discovered pages and issue counts are still being updated.</p>}
                    {!crawlPending && breakdown.length === 0 && <p className="text-sm text-muted">No breakdown returned yet.</p>}
                    {breakdown.map((b) => {
                      const healthPct = b.weight > 0 ? Math.max(0, Math.min(100, 100 - (b.penalty / b.weight) * 100)) : 100;
                      const barTone = healthPct >= 75 ? "bg-good" : healthPct >= 50 ? "bg-warn" : "bg-bad";
                      return (
                        <div key={b.category}>
                          <div className="mb-1 flex items-center justify-between gap-2 text-xs">
                            <span className="font-medium text-fg">{humanCategory(b.category)}</span>
                            <span className="text-muted">
                              {b.open_issues} open · −{num(b.penalty)} pts · {b.weight}% weight
                            </span>
                          </div>
                          <div className="h-2 overflow-hidden rounded-full bg-card2">
                            <div className={`h-full rounded-full ${barTone}`} style={{ width: `${healthPct}%` }} />
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </>
              )}
            </Card>

            <Card
              className="lg:col-span-3"
              title="Top issues"
              right={
                <Link href="/dashboard/audit/issues" className="text-xs font-semibold text-brand hover:underline">
                  View all issues →
                </Link>
              }
            >
              {health.loading && !health.data ? (
                <Spinner />
              ) : health.error ? (
                <ErrorState message={health.error} onRetry={health.reload} />
              ) : crawlPending ? (
                <p className="py-6 text-center text-sm text-warn">The crawler is still processing pages. Issues will appear when this run finishes.</p>
              ) : (health.data?.top_issues || []).length === 0 ? (
                <p className="py-6 text-center text-sm text-muted">No open issues — the last crawl came back clean.</p>
              ) : (
                <ul className="divide-y divide-line">
                  {(health.data.top_issues as any[]).map((issue, i) => (
                    <li key={issue.id ?? i} className="flex items-center gap-3 py-2.5 text-sm">
                      {issue.severity ? <SeverityBadge severity={issue.severity} /> : null}
                      <div className="min-w-0 flex-1">
                        <p className="truncate font-medium text-fg">{issueCodeLabel(issue.issue_code ?? issue.code ?? "issue")}</p>
                        {issue.target_url && (
                          <p className="truncate font-mono text-xs text-muted" title={issue.target_url}>
                            {urlPath(issue.target_url)}
                          </p>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>

          <Card className="mt-4" title="Recent runs" subtitle="Last 20 crawl runs for this site">
            <ResponsiveTable
              columns={runColumns}
              rows={runs.runs}
              rowKey={(r) => r.id}
              emptyMessage="No crawl runs yet — run your first crawl above."
            />
          </Card>
        </>
      )}
    </div>
  );
}
