import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";

/**
 * Universal Overview (app/dashboard/page.tsx) contract tests.
 *
 * The overview is search-first: SEO/GSC and performance KPIs lead, commerce is
 * demoted and gated behind a WooCommerce signal, while AI answer visibility,
 * performance, and uptime render from their real API payloads. Mirrors the
 * fetch-mocking idiom of emails-page.test.tsx:
 * the API layer is stubbed per path, the dashboard context is stubbed inline.
 */

// recharts' ResponsiveContainer needs ResizeObserver, which jsdom lacks.
class ResizeObserverStub {
  observe() {}
  unobserve() {}
  disconnect() {}
}
if (!(globalThis as any).ResizeObserver) {
  (globalThis as any).ResizeObserver = ResizeObserverStub;
}

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

const dash = vi.hoisted(() => ({
  state: {
    siteId: 1 as number | null,
    period: "last_30_days",
    rangeQuery: "",
    sites: [] as any[],
  },
}));

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => dash.state,
}));

import OverviewPage from "@/app/dashboard/page";

const WOO_SITE = {
  id: 1, name: "Test Shop", connection_status: "connected",
  wc_version: "9.4", plugin_version: "2.1.0", currency: "USD",
  last_sync_at: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
};

const NON_WOO_SITE = {
  id: 1, name: "Content Blog", connection_status: "connected",
  last_sync_at: new Date(Date.now() - 30 * 60 * 1000).toISOString(),
};

const SEO_OK = {
  range: { period: "Last 30 days", from: "2026-08-21", to: "2026-09-19", timezone: "UTC" },
  metrics: { clicks: 1200, impressions: 34000, ctr: 3.5, avg_position: 12.4 },
  deltas: { clicks: 8.2, impressions: -2.1, ctr: null, avg_position: null },
  series: [
    { date: "2026-09-18", clicks: 40, impressions: 900, ctr: 4.4, avg_position: 11.2 },
    { date: "2026-09-19", clicks: 52, impressions: 1100, ctr: 4.7, avg_position: 10.8 },
  ],
  has_data: true,
};

const HEALTH_OK = {
  site: { id: 1, name: "Test Shop", connection_status: "connected", last_sync_at: new Date().toISOString() },
  orders_total: 42,
  sync_logs: [],
};

const PERF_OK = {
  strategy: "mobile",
  run: {
    id: 1, run_at: new Date().toISOString(), performance_score: 76,
    accessibility_score: 95, best_practices_score: 92, seo_score: 100,
    lcp_ms: 2100, lcp_status: "good", inp_ms: 120, inp_status: "good", cls: 0.03, cls_status: "good",
  },
  issues: [],
};

const COMMERCE_OK = {
  range: { period: "Last 30 days", from: "2026-08-21", to: "2026-09-19", timezone: "UTC" },
  metrics: { revenue: 12345, orders: 88, average_order_value: 140.28, refunds: 2 },
  deltas: { revenue: 5.1, orders: null, average_order_value: null, refunds: null },
};

const VISIBILITY_OK = { metrics: { mention_rate: 42.5, runs_total: 8 } };
const UPTIME_OK = { has_monitor: true, availability_pct: 99.9, incidents: [] };

function mockApi(opts: {
  seo?: any; seoError?: string;
  health?: any; perf?: any;
  visibility?: any; uptime?: any;
  alerts?: any[]; insights?: any[];
  commerce?: any; revenueSeries?: any[];
} = {}) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    if (path.includes("/onboarding")) return Promise.resolve({ finished: true, dismissed: true, steps: [], completed: 0, total: 0, next: null });
    if (path.includes("/analytics/seo/overview")) {
      return opts.seoError ? Promise.reject({ message: opts.seoError }) : Promise.resolve(opts.seo ?? SEO_OK);
    }
    if (path.includes("/analytics/performance/latest")) return Promise.resolve(opts.perf ?? PERF_OK);
    if (path.includes("/ai-visibility")) return Promise.resolve(opts.visibility ?? VISIBILITY_OK);
    if (path.includes("/uptime")) return Promise.resolve(opts.uptime ?? UPTIME_OK);
    if (path.includes("/health")) return Promise.resolve(opts.health ?? HEALTH_OK);
    if (path.includes("/alerts")) return Promise.resolve({ alerts: opts.alerts ?? [] });
    if (path.includes("/insights")) return Promise.resolve({ insights: opts.insights ?? [] });
    if (path.includes("/analytics/overview")) return Promise.resolve(opts.commerce ?? COMMERCE_OK);
    if (path.includes("/analytics/revenue")) return Promise.resolve({ series: opts.revenueSeries ?? [] });
    return Promise.resolve({});
  });
}

describe("Overview page (Universal Overview)", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the commerce section for a WooCommerce-active site", async () => {
    dash.state = { siteId: 1, period: "last_30_days", rangeQuery: "", sites: [WOO_SITE] };
    mockApi({});
    render(<OverviewPage />);

    expect(await screen.findByText("Commerce")).toBeInTheDocument();
    // Node's locale may or may not add thousands separators — match either form.
    expect((await screen.findAllByText(/^\$12[,.\s]?345$/)).length).toBeGreaterThan(0);
    expect(screen.getByText("88")).toBeInTheDocument();
  });

  it("hides commerce for a non-woo site but keeps the search KPIs", async () => {
    dash.state = { siteId: 1, period: "last_30_days", rangeQuery: "", sites: [NON_WOO_SITE] };
    mockApi({});
    render(<OverviewPage />);

    // Search KPIs still render with real GSC figures...
    expect(await screen.findByText("Organic clicks")).toBeInTheDocument();
    expect(screen.getByText("Search impressions")).toBeInTheDocument();
    expect((await screen.findAllByText(/^1[,.\s]?200$/)).length).toBeGreaterThan(0);
    expect((await screen.findAllByText(/^34[,.\s]?000$/)).length).toBeGreaterThan(0);
    // ...while the commerce section never appears.
    expect(screen.queryByText("Commerce")).not.toBeInTheDocument();
    expect(screen.queryByText(/^\$12[,.\s]?345$/)).not.toBeInTheDocument();
  });

  it("shows an ErrorState (not zeros) when the SEO fetch fails", async () => {
    dash.state = { siteId: 1, period: "last_30_days", rangeQuery: "", sites: [WOO_SITE] };
    mockApi({ seoError: "Search Console data unavailable" });
    render(<OverviewPage />);

    expect(await screen.findByText("Search Console data unavailable")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /try again|retry/i })).toBeInTheDocument();
    // The clicks KPI must not fall back to a confident zero.
    expect(screen.queryByText(/^1[,.\s]?200$/)).not.toBeInTheDocument();
  });

  it("renders AI answer visibility and uptime from real metrics", async () => {
    dash.state = { siteId: 1, period: "last_30_days", rangeQuery: "", sites: [WOO_SITE] };
    mockApi({});
    render(<OverviewPage />);

    await screen.findByText("Organic clicks");
    expect(await screen.findByText("AI answer visibility")).toBeInTheDocument();
    expect(screen.getByText("42.5%")).toBeInTheDocument();
    expect(screen.getByText("99.9%")).toBeInTheDocument();
    expect(screen.getByText("Performance score")).toBeInTheDocument();
    expect(screen.queryByText("Coming soon")).not.toBeInTheDocument();
  });
});
