import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Intelligence page (spec Phase 7).
 *
 * The risk this page carries is confident presentation of numbers that are
 * not there. These tests pin the places where a plausible UI would invent
 * something: an empty report must say *which* kind of empty it is, a cohort
 * row must not be padded out to a uniform width with zeroes, and the
 * attribution and correlation caveats must survive on screen rather than
 * being trimmed as clutter.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({
    siteId: 1,
    sites: [{ id: 1, name: "Test Shop" }],
    period: "last_30_days",
  }),
}));

import IntelligencePage from "@/app/dashboard/intelligence/page";

/** Route each endpoint to a fixture; anything unlisted answers "no data". */
function routes(map: Record<string, any>) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const hit = Object.keys(map).find((k) => path.includes(k));
    return Promise.resolve(hit ? map[hit] : { has_data: false });
  });
}

describe("Intelligence page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("names the specific reason a report is empty", async () => {
    routes({
      "/lifetime-value": { has_data: false, reason: "not_enough_customers" },
      "/churn-risk": { has_data: false, reason: "no_managed_stock" },
    });
    render(<IntelligencePage />);

    // "Not enough customers" and a generic "no data" send people to different
    // places — one waits, the other checks the connection.
    await waitFor(() =>
      expect(screen.getByText(/Not enough customers yet to segment/)).toBeInTheDocument(),
    );
  });

  it("falls back to a plain message for an unrecognised reason", async () => {
    routes({ "/lifetime-value": { has_data: false, reason: "something_new" } });
    render(<IntelligencePage />);

    await waitFor(() =>
      expect(screen.getAllByText(/Not enough data yet for this report/).length).toBeGreaterThan(0),
    );
  });

  it("renders lifetime value figures from the API", async () => {
    routes({
      "/lifetime-value": {
        has_data: true,
        currency: "USD",
        avg_lifetime_value: 240.5,
        median_lifetime_value: 180,
        avg_order_value: 120.25,
        repeat_rate: 41.3,
        avg_orders_per_customer: 2,
        top_decile_share: 55.2,
      },
    });
    render(<IntelligencePage />);

    await waitFor(() => expect(screen.getByText("USD 240.5")).toBeInTheDocument());
    expect(screen.getByText("41.3%")).toBeInTheDocument();
    expect(screen.getByText("55.2%")).toBeInTheDocument();
  });

  it("gives a cohort row only the months it has lived through", async () => {
    routes({
      "/cohorts": {
        has_data: true,
        cohorts: [
          {
            cohort: "2026-05",
            customers: 40,
            periods: [
              { offset: 0, customers: 40, rate: 100, revenue: 4000 },
              { offset: 1, customers: 10, rate: 25, revenue: 900 },
              { offset: 2, customers: 4, rate: 10, revenue: 400 },
            ],
          },
          // This month's cohort: one observable month, nothing after it.
          { cohort: "2026-07", customers: 12, periods: [{ offset: 0, customers: 12, rate: 100, revenue: 1200 }] },
        ],
      },
    });
    render(<IntelligencePage />);

    await waitFor(() => expect(screen.getByText("2026-05")).toBeInTheDocument());

    const shortRow = screen.getByText("2026-07").closest("tr")!;
    // Cohort + size + one period = 3 cells. A padded row would show 0% for
    // months that have not happened, which reads as a retention collapse.
    const shortCells = [...shortRow.querySelectorAll("td")];
    expect(shortCells).toHaveLength(3);
    expect(shortCells.map((c) => c.textContent)).not.toContain("0%");

    const fullRow = screen.getByText("2026-05").closest("tr")!;
    expect(fullRow.querySelectorAll("td")).toHaveLength(5);
  });

  it("keeps the last-click caveat on the channels table", async () => {
    routes({
      "/channels": {
        has_data: true,
        model: "last_click",
        caveat: "GA4 reports last-click channel attribution. Channels that assist earlier in a journey are undercredited here.",
        channels: [
          { channel: "Organic Search", sessions: 1000, conversions: 50, conversion_rate: 5, revenue: 5000, revenue_per_session: 5 },
        ],
      },
    });
    render(<IntelligencePage />);

    await userEvent.click(screen.getByRole("button", { name: /Acquisition/ }));

    await waitFor(() => expect(screen.getByText("Organic Search")).toBeInTheDocument());
    expect(screen.getByText(/undercredited/)).toBeInTheDocument();
  });

  it("labels the speed/revenue relationship as correlation", async () => {
    routes({
      "/performance-correlation": {
        has_data: true,
        paired_days: 21,
        caveat: "Correlation, not causation — both metrics move with traffic and season.",
        performance_score_vs_revenue: { r: 0.62, strength: "moderate" },
        lcp_vs_revenue: { r: -0.55, strength: "moderate" },
      },
    });
    render(<IntelligencePage />);

    await userEvent.click(screen.getByRole("button", { name: /Acquisition/ }));

    await waitFor(() => expect(screen.getByText("0.62")).toBeInTheDocument());
    expect(screen.getByText(/not causation/)).toBeInTheDocument();
  });

  it("says explicitly when no day was unusual", async () => {
    routes({
      "/anomalies": {
        has_data: true,
        baseline: { median_sessions: 420, deviation: 30 },
        anomalies: [],
      },
    });
    render(<IntelligencePage />);

    await userEvent.click(screen.getByRole("button", { name: /Acquisition/ }));

    // Silence must be stated, not left as an empty table the reader has to
    // interpret as either "fine" or "broken".
    await waitFor(() => expect(screen.getByText(/Nothing unusual/)).toBeInTheDocument());
    expect(screen.getByText(/420 sessions/)).toBeInTheDocument();
  });

  it("shows stock risk in days of cover, not raw stock", async () => {
    routes({
      "/stock-risk": {
        has_data: true,
        window_days: 30,
        tracked_products: 12,
        at_risk: 2,
        products: [
          { product_id: 1, name: "Fast mover", stock: 10, units_per_day: 1, days_of_cover: 10, risk: "high" },
          { product_id: 2, name: "Slow mover", stock: 10, units_per_day: 0.1, days_of_cover: 100, risk: "none" },
        ],
      },
    });
    render(<IntelligencePage />);

    await userEvent.click(screen.getByRole("button", { name: /Products/ }));

    await waitFor(() => expect(screen.getByText("Fast mover")).toBeInTheDocument());
    expect(screen.getByText("10d")).toBeInTheDocument();
    expect(screen.getByText("100d")).toBeInTheDocument();
    // Same stock level, different urgency — that is the whole point.
    expect(screen.getByText("high")).toBeInTheDocument();
  });

  it("reports a discount that shrank baskets as a loss", async () => {
    routes({
      "/discounts": {
        has_data: true,
        currency: "USD",
        orders: 10,
        discounted: { orders: 4, share: 40, revenue: 320, avg_order_value: 80, total_discount: 80, avg_discount: 20, discount_rate: 20 },
        full_price: { orders: 6, share: 60, revenue: 600, avg_order_value: 100 },
        aov_difference: -20,
        aov_difference_pct: -20,
      },
    });
    render(<IntelligencePage />);

    await userEvent.click(screen.getByRole("button", { name: /Products/ }));

    await waitFor(() => expect(screen.getByText(/USD 20 smaller/)).toBeInTheDocument());
    expect(screen.getByText(/after giving away USD 80/)).toBeInTheDocument();
  });

  it("surfaces a failed request instead of showing an empty report", async () => {
    apiMocks.apiGet.mockRejectedValue({ status: 500, message: "Report failed" });
    render(<IntelligencePage />);

    await waitFor(() => expect(screen.getAllByText("Report failed").length).toBeGreaterThan(0));
  });
});
