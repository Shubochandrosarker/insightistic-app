"use client";
import Link from "next/link";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { ErrorState, SourceFreshness } from "@/components/dashboard/states";
import { Spinner } from "@/components/ui/Spinner";
import { ResponsiveTable, type Column } from "@/components/app-shell/ResponsiveTable";
import { RunBar, SeverityBadge, StatusChip, issueCodeLabel, urlPath, useLatestRun, type CrawlRun } from "@/components/dashboard/audit";
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

      {runs.loading ? (
        <Spinner />
      ) : notCrawled ? (
        <EmptyState
          icon={<FileSearch size={20} />}
          title="Run your first crawl"
          hint="Insightistic hasn't crawled this site yet. A crawl maps your pages, checks every response, and collects broken links and technical SEO issues."
          action={<RunBar siteId={siteId} run={null} onSettled={refreshAll} compact />}
        />
      ) : (
        <>
          <Card title="Crawler" right={<SourceFreshness label="Last run" asOf={runs.latestRun?.started_at ?? null} />}>
            <RunBar siteId={siteId} run={runs.latestRun} onSettled={refreshAll} />
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
