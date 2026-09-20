import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";

/**
 * Phase 9 Cloudflare Edge Intelligence UI (roadmap doc 15). Mocking mirrors
 * tests/traffic-page.test.tsx — the API layer is stubbed per path, the real
 * page renders. Pins: the overview KPI row with the cache hit ratio math,
 * the bot analytics table when the plan exposes it vs the honest plan-gated
 * card when it doesn't, the traffic-gap explanation strings rendered as the
 * feature they are, and the calm connect/awaiting-sync states.
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
}));

vi.mock("@/lib/api", () => apiMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({
    siteId: 1,
    sites: [{ id: 1, name: "Test Shop" }],
    period: "last_30_days",
    rangeQuery: "",
  }),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

import CloudflarePage from "@/app/dashboard/cloudflare/page";

const payload = {
  has_data: true,
  range: { days: 30, from: "2026-08-22", to: "2026-09-20" },
  overview: {
    requests: 1500,
    bandwidth_gb: 12.34,
    cached_requests: 700,
    cache_hit_ratio: 46.7, // 700 / 1500
    bandwidth_saved_pct: 38.2,
    encrypted_pct: 91.4,
    pageviews: 812,
    threats: 34,
    client_4xx: 41,
    client_5xx: 3,
    trend: [
      { date: "2026-09-01", requests: 600, bytes: 500000000, cached_requests: 280, pageviews: 300, threats: 10, client_4xx: 12, client_5xx: 1 },
      { date: "2026-09-02", requests: 900, bytes: 700000000, cached_requests: 420, pageviews: 512, threats: 24, client_4xx: 29, client_5xx: 2 },
    ],
  },
  geo: { top_countries: [{ country: "US", requests: 1100 }, { country: "DE", requests: 400 }] },
  security: {
    threats_total: 34,
    client_4xx: 41,
    client_5xx: 3,
    actions: { block: 20, challenge: 14 },
    trend: [{ date: "2026-09-01", threats: 10 }, { date: "2026-09-02", threats: 24 }],
  },
  bots: {
    available: true,
    rows: [
      { bot_name: "Googlebot", classification: "search_bot", requests: 120, blocked: 0 },
      { bot_name: "GPTBot", classification: "ai_crawler", requests: 40, blocked: 5 },
    ],
  },
  traffic_gap: {
    edge_requests: 1500,
    ga4_sessions: 310,
    gap_ratio_pct: 20.7,
    explanations: {
      requests_are_not_sessions:
        "An edge request is any asset served through Cloudflare; a GA4 session is a visit — the two measure different units.",
      bots_never_hit_ga4: "Bots do not execute JavaScript, so they generate edge requests but never appear in GA4.",
    },
  },
  freshness: { last_data_date: "2026-09-19", hours_old: 30 },
};

function mockApi(body: any) {
  apiMocks.apiGet.mockImplementation(() => Promise.resolve(body));
}

describe("Cloudflare edge page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the overview KPIs with the cache hit ratio math shown, scoped to the days window", async () => {
    mockApi(payload);
    render(<CloudflarePage />);

    expect(await screen.findByText("46.7%")).toBeInTheDocument();
    expect(screen.getByText("38.2%")).toBeInTheDocument();
    expect(screen.getByText("91.4%")).toBeInTheDocument();
    expect(screen.getAllByText("1,500").length).toBeGreaterThanOrEqual(1);
    expect(screen.getByText("12.34")).toBeInTheDocument();
    expect(screen.getByText("812")).toBeInTheDocument();

    // Default window is 30 days, and the days toggle refetches with ?days=.
    const calls = apiMocks.apiGet.mock.calls.map(([p]) => String(p));
    expect(calls.some((p) => p.includes("/cloudflare?days=30"))).toBe(true);
  });

  it("renders the bot table with classifications when the plan exposes bot analytics", async () => {
    mockApi(payload);
    render(<CloudflarePage />);

    expect((await screen.findAllByText("GPTBot")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Googlebot").length).toBeGreaterThan(0);
    // AI crawler badge vs Search bot badge — the classification is the point.
    expect(screen.getAllByText("AI crawler").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Search bot").length).toBeGreaterThan(0);
  });

  it("shows the honest plan-gated card — never an error — when the plan lacks bot analytics", async () => {
    mockApi({ ...payload, bots: { available: false, rows: [], reason: "plan_does_not_expose_bot_analytics" } });
    render(<CloudflarePage />);

    expect(await screen.findByText("Bot analytics isn't available on this Cloudflare plan")).toBeInTheDocument();
    expect(screen.getByText("plan_does_not_expose_bot_analytics")).toBeInTheDocument();
    expect(screen.queryByText("GPTBot")).not.toBeInTheDocument();
  });

  it("renders the traffic gap side by side with its explanation strings as the feature", async () => {
    mockApi(payload);
    render(<CloudflarePage />);

    expect(await screen.findByText("Why these numbers differ")).toBeInTheDocument();
    expect(screen.getByText(/a GA4 session is a visit/)).toBeInTheDocument();
    expect(screen.getByText(/never appear in GA4/)).toBeInTheDocument();
    expect(screen.getByText("310")).toBeInTheDocument();
    expect(screen.getByText(/Sessions are 20.7% of edge requests/)).toBeInTheDocument();
  });

  it("shows the connect hint when no edge data has synced yet", async () => {
    mockApi({
      has_data: false,
      hint: "connect_cloudflare",
      message: "Connect Cloudflare (Zone Analytics: Read token + zone) to start collecting edge intelligence.",
    });
    render(<CloudflarePage />);

    expect(await screen.findByText("Connect Cloudflare in Integrations")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Open Integrations" })).toBeInTheDocument();
  });

  it("shows the awaiting-sync state for a connected site that has no rows yet", async () => {
    mockApi({ has_data: false, hint: "connected_awaiting_sync", message: "Cloudflare is connected; edge analytics appear after the nightly sync fills them." });
    render(<CloudflarePage />);

    expect(await screen.findByText("Connected — first edge sync will fill this within a day.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Open Integrations" })).not.toBeInTheDocument();
  });
});
