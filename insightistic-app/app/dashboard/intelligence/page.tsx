"use client";
import { useState } from "react";
import { useDashboard } from "@/lib/dashboard";
import { useApi } from "@/lib/useApi";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Spinner } from "@/components/ui/Spinner";
import { Brain, Users, Package, Search, Radio } from "lucide-react";

/**
 * Advanced business intelligence (spec Phase 7).
 *
 * Each panel fetches its own endpoint, so the page fills in as results land
 * rather than blocking everything on the cohort query, which walks the whole
 * order history.
 *
 * Every panel renders the API's own `reason` when there is no data. "Not
 * enough customers to segment" and "no orders at all" need different words on
 * screen — a shared "no data" message would leave people re-checking a
 * connection that is working fine.
 */

const TABS = [
  { key: "customers", label: "Customers", icon: Users },
  { key: "products", label: "Products", icon: Package },
  { key: "search", label: "Search", icon: Search },
  { key: "acquisition", label: "Acquisition", icon: Radio },
] as const;

type Tab = (typeof TABS)[number]["key"];

const REASONS: Record<string, string> = {
  not_enough_customers: "Not enough customers yet to segment reliably.",
  not_enough_history: "Not enough daily history yet to know what normal looks like.",
  not_enough_queries: "Not enough ranking queries yet to build a benchmark.",
  not_enough_pages: "Not enough ranking pages to compare.",
  not_enough_paired_days: "Not enough days with both a speed test and orders.",
  no_comparison_data: "No previous period to compare against yet.",
  no_managed_stock: "No products with stock tracking enabled in WooCommerce.",
  no_multi_item_baskets: "No orders yet contained more than one product.",
  no_performance_runs: "No completed PageSpeed runs in this period.",
};

function money(v: number | null | undefined, currency?: string | null) {
  if (v == null) return "—";
  return `${currency ? currency + " " : ""}${v.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
}

function num(v: number | null | undefined) {
  return v == null ? "—" : v.toLocaleString();
}

function pct(v: number | null | undefined) {
  return v == null ? "—" : `${v}%`;
}

/** A panel that owns its own request, loading and empty states. */
function Panel({
  title,
  hint,
  path,
  children,
}: {
  title: string;
  hint?: string;
  path: string | null;
  children: (data: any) => React.ReactNode;
}) {
  const { data, loading, error } = useApi<any>(path);

  return (
    <Card>
      <div className="mb-3">
        <h2 className="text-sm font-semibold text-fg">{title}</h2>
        {hint && <p className="mt-0.5 text-xs text-muted">{hint}</p>}
      </div>

      {loading ? (
        <Spinner />
      ) : error ? (
        <p className="text-sm text-bad">{error}</p>
      ) : !data?.has_data ? (
        <p className="text-sm text-muted">
          {REASONS[data?.reason] || "Not enough data yet for this report."}
        </p>
      ) : (
        children(data)
      )}
    </Card>
  );
}

function Th({ children, right }: { children: React.ReactNode; right?: boolean }) {
  return (
    <th className={`pb-2 text-[11px] font-semibold uppercase tracking-wide text-muted ${right ? "text-right" : "text-left"}`}>
      {children}
    </th>
  );
}

function Td({ children, right }: { children: React.ReactNode; right?: boolean }) {
  return <td className={`py-2 text-sm text-fg ${right ? "text-right tabular-nums" : ""}`}>{children}</td>;
}

function Table({ head, children }: { head: React.ReactNode; children: React.ReactNode }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[420px]">
        <thead className="border-b border-line">
          <tr>{head}</tr>
        </thead>
        <tbody className="divide-y divide-line">{children}</tbody>
      </table>
    </div>
  );
}

const RISK_TONE: Record<string, "red" | "amber" | "green" | "slate"> = {
  critical: "red", high: "red", medium: "amber", low: "green", none: "slate", idle: "slate",
};

export default function IntelligencePage() {
  const { siteId, sites, period } = useDashboard();
  const site = sites.find((s) => s.id === siteId);
  const [tab, setTab] = useState<Tab>("customers");

  const base = siteId ? `/sites/${siteId}/intelligence` : null;
  const q = period ? `?period=${period}` : "";

  if (!siteId) {
    return <EmptyState icon={<Brain size={20} />} title="No site connected" hint="Connect a store to see business intelligence." />;
  }

  return (
    <div>
      <PageHeader
        title="Intelligence"
        subtitle={`Segments, cohorts, stock risk and search opportunities for ${site?.name || "your store"}.`}
      />

      <div className="mb-5 flex flex-wrap gap-2">
        {TABS.map((t) => {
          const Icon = t.icon;
          return (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={`inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold transition ${
                tab === t.key ? "bg-brand text-white" : "border border-line bg-card text-muted hover:text-fg"
              }`}
            >
              <Icon size={13} /> {t.label}
            </button>
          );
        })}
      </div>

      {tab === "customers" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel
            title="Lifetime value"
            hint="Revenue already taken, net of refunds — not a forecast."
            path={base && `${base}/lifetime-value`}
          >
            {(d) => (
              <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {[
                  ["Avg lifetime value", money(d.avg_lifetime_value, d.currency)],
                  ["Median", money(d.median_lifetime_value, d.currency)],
                  ["Avg order value", money(d.avg_order_value, d.currency)],
                  ["Repeat rate", pct(d.repeat_rate)],
                  ["Orders per customer", num(d.avg_orders_per_customer)],
                  ["Top 10% hold", pct(d.top_decile_share)],
                ].map(([label, value]) => (
                  <div key={label as string} className="rounded-xl border border-line bg-card2 p-3">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{label}</div>
                    <div className="mt-1 text-lg font-bold text-fg">{value}</div>
                  </div>
                ))}
              </div>
            )}
          </Panel>

          <Panel
            title="RFM segments"
            hint="Scored against this store's own customers, not fixed thresholds."
            path={base && `${base}/rfm`}
          >
            {(d) => (
              <Table
                head={
                  <>
                    <Th>Segment</Th>
                    <Th right>Customers</Th>
                    <Th right>Revenue</Th>
                    <Th right>Share</Th>
                  </>
                }
              >
                {d.segments
                  .filter((s: any) => s.customers > 0)
                  .map((s: any) => (
                    <tr key={s.segment}>
                      <Td>
                        <div className="font-medium">{s.label}</div>
                        <div className="text-xs text-muted">{s.description}</div>
                      </Td>
                      <Td right>{num(s.customers)}</Td>
                      <Td right>{money(s.revenue, d.currency)}</Td>
                      <Td right>{pct(s.revenue_share)}</Td>
                    </tr>
                  ))}
              </Table>
            )}
          </Panel>

          <Panel
            title="Churn risk"
            hint="Overdue against each customer's own buying rhythm."
            path={base && `${base}/churn-risk`}
          >
            {(d) => (
              <>
                <p className="mb-3 text-xs text-muted">
                  {num(d.total_at_risk)} customers overdue · {money(d.revenue_at_risk, d.currency)} of past revenue ·{" "}
                  {num(d.single_order_customers)} one-off customers excluded
                </p>
                <Table
                  head={
                    <>
                      <Th>Customer</Th>
                      <Th right>Usual gap</Th>
                      <Th right>Silent for</Th>
                      <Th right>Value</Th>
                      <Th right>Risk</Th>
                    </>
                  }
                >
                  {d.customers.map((c: any) => (
                    <tr key={c.customer_id}>
                      <Td>#{c.customer_id}</Td>
                      <Td right>{c.typical_gap_days}d</Td>
                      <Td right>{c.days_since_last}d</Td>
                      <Td right>{money(c.revenue, d.currency)}</Td>
                      <Td right>
                        <Badge tone={RISK_TONE[c.risk] || "slate"} dot>{c.risk}</Badge>
                      </Td>
                    </tr>
                  ))}
                </Table>
              </>
            )}
          </Panel>

          <Panel
            title="Acquisition cohorts"
            hint="Each row is one month's new customers and how many came back."
            path={base && `${base}/cohorts`}
          >
            {(d) => (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[520px]">
                  <thead className="border-b border-line">
                    <tr>
                      <Th>Cohort</Th>
                      <Th right>Size</Th>
                      {Array.from({ length: Math.max(...d.cohorts.map((c: any) => c.periods.length)) }, (_, i) => (
                        <Th key={i} right>M{i}</Th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-line">
                    {d.cohorts.map((c: any) => (
                      <tr key={c.cohort}>
                        <Td>{c.cohort}</Td>
                        <Td right>{num(c.customers)}</Td>
                        {c.periods.map((p: any, i: number) => (
                          <Td key={i} right>
                            {/* Rows are only as wide as the months that have
                                actually elapsed, so a short row means "not yet",
                                never "nobody came back". */}
                            <span className={p.rate >= 20 ? "font-semibold text-brand-700" : "text-muted"}>
                              {pct(p.rate)}
                            </span>
                          </Td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Panel>
        </div>
      )}

      {tab === "products" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel
            title="Stock risk"
            hint="Days of cover at the current selling rate."
            path={base && `${base}/stock-risk${q}`}
          >
            {(d) => (
              <>
                <p className="mb-3 text-xs text-muted">
                  {num(d.at_risk)} of {num(d.tracked_products)} tracked products need attention
                </p>
                <Table
                  head={
                    <>
                      <Th>Product</Th>
                      <Th right>Stock</Th>
                      <Th right>Per day</Th>
                      <Th right>Cover</Th>
                      <Th right>Risk</Th>
                    </>
                  }
                >
                  {d.products.map((p: any) => (
                    <tr key={p.product_id}>
                      <Td>{p.name}</Td>
                      <Td right>{num(p.stock)}</Td>
                      <Td right>{num(p.units_per_day)}</Td>
                      <Td right>{p.days_of_cover == null ? "—" : `${p.days_of_cover}d`}</Td>
                      <Td right>
                        <Badge tone={RISK_TONE[p.risk] || "slate"} dot>{p.risk}</Badge>
                      </Td>
                    </tr>
                  ))}
                </Table>
              </>
            )}
          </Panel>

          <Panel
            title="Bought together"
            hint="Ranked by lift — how much more often than chance."
            path={base && `${base}/affinity${q}`}
          >
            {(d) => (
              <Table
                head={
                  <>
                    <Th>Pair</Th>
                    <Th right>Baskets</Th>
                    <Th right>Lift</Th>
                  </>
                }
              >
                {d.pairs.map((p: any, i: number) => (
                  <tr key={i}>
                    <Td>
                      <div className="font-medium">{p.product_a.name}</div>
                      <div className="text-xs text-muted">+ {p.product_b.name}</div>
                    </Td>
                    <Td right>{num(p.together)}</Td>
                    <Td right>{p.lift == null ? "—" : `${p.lift}×`}</Td>
                  </tr>
                ))}
              </Table>
            )}
          </Panel>

          <Panel
            title="Discount impact"
            hint="Are discounted baskets actually bigger?"
            path={base && `${base}/discounts${q}`}
          >
            {(d) => (
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  {[
                    ["Discounted orders", `${num(d.discounted.orders)} (${pct(d.discounted.share)})`],
                    ["Full-price orders", `${num(d.full_price.orders)} (${pct(d.full_price.share)})`],
                    ["Discounted AOV", money(d.discounted.avg_order_value, d.currency)],
                    ["Full-price AOV", money(d.full_price.avg_order_value, d.currency)],
                  ].map(([label, value]) => (
                    <div key={label as string} className="rounded-xl border border-line bg-card2 p-3">
                      <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">{label}</div>
                      <div className="mt-1 text-base font-bold text-fg">{value}</div>
                    </div>
                  ))}
                </div>
                {d.aov_difference != null && (
                  <p className={`text-sm ${d.aov_difference >= 0 ? "text-good" : "text-bad"}`}>
                    Discounted baskets are {money(Math.abs(d.aov_difference), d.currency)}{" "}
                    {d.aov_difference >= 0 ? "larger" : "smaller"} than full-price ones ({pct(d.aov_difference_pct)}),
                    after giving away {money(d.discounted.total_discount, d.currency)}.
                  </p>
                )}
              </div>
            )}
          </Panel>

          <Panel
            title="Declining products"
            hint="Against the same-length window immediately before."
            path={base && `${base}/declining-products${q}`}
          >
            {(d) => (
              <Table
                head={
                  <>
                    <Th>Product</Th>
                    <Th right>Units</Th>
                    <Th right>Was</Th>
                    <Th right>Change</Th>
                  </>
                }
              >
                {d.products.map((p: any) => (
                  <tr key={p.product_id}>
                    <Td>{p.name}</Td>
                    <Td right>{num(p.units)}</Td>
                    <Td right>{num(p.previous_units)}</Td>
                    <Td right>
                      <span className="text-bad">{p.change_pct}%</span>
                    </Td>
                  </tr>
                ))}
              </Table>
            )}
          </Panel>
        </div>
      )}

      {tab === "search" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel
            title="CTR opportunities"
            hint="Queries clicking below this site's own benchmark for their position."
            path={base && `${base}/ctr-opportunities${q}`}
          >
            {(d) => (
              <Table
                head={
                  <>
                    <Th>Query</Th>
                    <Th right>Pos</Th>
                    <Th right>CTR</Th>
                    <Th right>Benchmark</Th>
                    <Th right>Potential</Th>
                  </>
                }
              >
                {d.queries.map((q2: any, i: number) => (
                  <tr key={i}>
                    <Td>{q2.query}</Td>
                    <Td right>{q2.position}</Td>
                    <Td right>{pct(q2.ctr)}</Td>
                    <Td right>{pct(q2.benchmark_ctr)}</Td>
                    <Td right>+{num(q2.potential_clicks)}</Td>
                  </tr>
                ))}
              </Table>
            )}
          </Panel>

          <Panel
            title="Content decay"
            hint="Pages losing clicks, with what the data says about why."
            path={base && `${base}/content-decay${q}`}
          >
            {(d) => (
              <Table
                head={
                  <>
                    <Th>Page</Th>
                    <Th right>Clicks</Th>
                    <Th right>Change</Th>
                    <Th>Signal</Th>
                  </>
                }
              >
                {d.pages.map((p: any, i: number) => (
                  <tr key={i}>
                    <Td>{p.path}</Td>
                    <Td right>{num(p.clicks)}</Td>
                    <Td right>
                      <span className="text-bad">{p.click_change_pct}%</span>
                    </Td>
                    <Td>
                      <span className="text-xs text-muted">{p.diagnosis.replace(/_/g, " ")}</span>
                    </Td>
                  </tr>
                ))}
              </Table>
            )}
          </Panel>

          <Panel
            title="Cannibalization candidates"
            hint="Several pages ranking for the same topic."
            path={base && `${base}/cannibalization${q}`}
          >
            {(d) => (
              <>
                <p className="mb-3 text-xs text-muted">{d.caveat}</p>
                <div className="space-y-3">
                  {d.candidates.map((c: any, i: number) => (
                    <div key={i} className="rounded-xl border border-line bg-card2 p-3">
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-sm font-semibold capitalize text-fg">{c.topic}</span>
                        <span className="text-xs text-muted">{c.competing_pages} pages · best #{c.best_position}</span>
                      </div>
                      <ul className="mt-2 space-y-1">
                        {c.pages.map((p: any, j: number) => (
                          <li key={j} className="flex items-center justify-between gap-2 text-xs">
                            <span className="truncate text-muted">{p.path}</span>
                            <span className="shrink-0 tabular-nums text-fg">#{p.position}</span>
                          </li>
                        ))}
                      </ul>
                    </div>
                  ))}
                </div>
              </>
            )}
          </Panel>
        </div>
      )}

      {tab === "acquisition" && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Panel
            title="Channels"
            hint="Last-click, as GA4 reports it."
            path={base && `${base}/channels${q}`}
          >
            {(d) => (
              <>
                <p className="mb-3 text-xs text-muted">{d.caveat}</p>
                <Table
                  head={
                    <>
                      <Th>Channel</Th>
                      <Th right>Sessions</Th>
                      <Th right>CVR</Th>
                      <Th right>Revenue</Th>
                      <Th right>Per session</Th>
                    </>
                  }
                >
                  {d.channels.map((c: any) => (
                    <tr key={c.channel}>
                      <Td>{c.channel}</Td>
                      <Td right>{num(c.sessions)}</Td>
                      <Td right>{pct(c.conversion_rate)}</Td>
                      <Td right>{money(c.revenue)}</Td>
                      <Td right>{money(c.revenue_per_session)}</Td>
                    </tr>
                  ))}
                </Table>
              </>
            )}
          </Panel>

          <Panel
            title="Traffic anomalies"
            hint="Days far outside this site's own normal range."
            path={base && `${base}/anomalies${q}`}
          >
            {(d) =>
              d.anomalies.length === 0 ? (
                <p className="text-sm text-muted">
                  Nothing unusual — every day sat within the normal range (median{" "}
                  {num(d.baseline?.median_sessions)} sessions).
                </p>
              ) : (
                <Table
                  head={
                    <>
                      <Th>Date</Th>
                      <Th right>Sessions</Th>
                      <Th right>Expected</Th>
                      <Th right>Change</Th>
                    </>
                  }
                >
                  {d.anomalies.map((a: any) => (
                    <tr key={a.date}>
                      <Td>{a.date}</Td>
                      <Td right>{num(a.sessions)}</Td>
                      <Td right>{num(a.expected)}</Td>
                      <Td right>
                        <span className={a.direction === "spike" ? "text-good" : "text-bad"}>
                          {a.change_pct != null && a.change_pct > 0 ? "+" : ""}
                          {pct(a.change_pct)}
                        </span>
                      </Td>
                    </tr>
                  ))}
                </Table>
              )
            }
          </Panel>

          <Panel
            title="Speed and revenue"
            hint="Correlation only — a reason to test, not proof."
            path={base && `${base}/performance-correlation${q}`}
          >
            {(d) => (
              <div className="space-y-3">
                <div className="grid grid-cols-2 gap-3">
                  <div className="rounded-xl border border-line bg-card2 p-3">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">Score vs revenue</div>
                    <div className="mt-1 text-lg font-bold text-fg">{d.performance_score_vs_revenue.r ?? "—"}</div>
                    <div className="text-xs text-muted">{d.performance_score_vs_revenue.strength}</div>
                  </div>
                  <div className="rounded-xl border border-line bg-card2 p-3">
                    <div className="text-[10px] font-semibold uppercase tracking-wide text-muted">LCP vs revenue</div>
                    <div className="mt-1 text-lg font-bold text-fg">{d.lcp_vs_revenue?.r ?? "—"}</div>
                    <div className="text-xs text-muted">{d.lcp_vs_revenue?.strength ?? "not enough data"}</div>
                  </div>
                </div>
                <p className="text-xs text-muted">
                  {d.paired_days} paired days. {d.caveat}
                </p>
              </div>
            )}
          </Panel>
        </div>
      )}
    </div>
  );
}
