import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 6 workstream: the Search Performance source toggle (roadmap doc 11
 * §Search Intelligence UI). Mocking mirrors tests/traffic-page.test.tsx — the
 * API layer is stubbed, the real page renders. Pins: GSC stays the default
 * view, switching to Bing re-fetches with &source=bing and renders the weekly
 * buckets table, Combined hides position/CTR with the doc's no-blending
 * caption, and an empty Bing view points at Integrations instead of looking
 * like a broken page.
 */

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

import SeoPage from "@/app/dashboard/seo/page";

function overview(over: Record<string, any> = {}) {
  return {
    source: "gsc",
    metrics: { clicks: 120, impressions: 4000, ctr: 3, avg_position: 8.4 },
    deltas: { clicks: 5, impressions: 200, ctr: 0.2, avg_position: -0.9 },
    series: [
      { date: "2026-09-01", clicks: 10 },
      { date: "2026-09-02", clicks: 12 },
    ],
    has_data: true,
    position_sources: null,
    ...over,
  };
}

const weeklyRows = [
  {
    week_end: "2026-09-12",
    query: "bing running shoes",
    clicks: 12,
    impressions: 900,
    avg_click_position: 3.5,
    avg_impression_position: 14.2,
  },
  {
    week_end: "2026-09-05",
    query: "trail shoes bing",
    clicks: 0,
    impressions: 300,
    avg_click_position: null,
    avg_impression_position: 22.7,
  },
];

/** Route /analytics/seo/* calls by the ?source= the page appended. */
function seoRouter(responses: { gsc?: any; bing?: any; combined?: any; weekly?: any[] } = {}) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/analytics/seo/bing?")) return Promise.resolve({ weekly: responses.weekly ?? weeklyRows });
    const source = p.match(/source=(\w+)/)?.[1] ?? "gsc";
    const body =
      responses[source as "gsc" | "bing" | "combined"] ??
      overview({ source, has_data: source === "bing" ? false : true });
    return Promise.resolve(body);
  });
}

function overviewCalls() {
  return apiMocks.apiGet.mock.calls.map(([p]) => String(p)).filter((p) => p.includes("/overview"));
}

describe("Search Performance source toggle", () => {
  beforeEach(() => vi.clearAllMocks());

  it("defaults to Google: fetches with source=gsc and keeps the GSC layout (no Bing table)", async () => {
    seoRouter();
    render(<SeoPage />);

    expect(await screen.findByText("Avg position")).toBeInTheDocument();
    expect(screen.getByText("8.4")).toBeInTheDocument();
    expect(screen.getByText("Top queries")).toBeInTheDocument();
    expect(screen.queryByText("Bing query stats (weekly)")).not.toBeInTheDocument();

    const paths = overviewCalls();
    expect(paths.some((p) => p.includes("&source=gsc"))).toBe(true);
    expect(apiMocks.apiGet.mock.calls.map(([p]) => String(p)).some((p) => p.includes("/analytics/seo/bing?"))).toBe(false);
  });

  it("switching to Bing fetches with &source=bing and renders the weekly query table", async () => {
    seoRouter({ bing: overview({ source: "bing", metrics: { clicks: 12, impressions: 900, ctr: 1.33, avg_position: 14.2 } }) });
    const user = userEvent.setup();
    render(<SeoPage />);

    await screen.findByText("Avg position");
    await user.click(screen.getByRole("button", { name: "Bing" }));

    expect(await screen.findByText("Bing query stats (weekly)")).toBeInTheDocument();
    expect(screen.getAllByText("bing running shoes").length).toBeGreaterThan(0);
    expect(screen.getAllByText("trail shoes bing").length).toBeGreaterThan(0);
    // Weekly buckets, with em-dashes where Bing reported no click position.
    expect(screen.getAllByText("2026-09-12").length).toBeGreaterThan(0);
    expect(screen.getAllByText("—").length).toBeGreaterThan(0);

    const paths = overviewCalls();
    expect(paths.some((p) => p.includes("&source=bing"))).toBe(true);
    expect(apiMocks.apiGet.mock.calls.map(([p]) => String(p)).some((p) => p.includes("/analytics/seo/bing?window=90"))).toBe(true);
  });

  it("Combined view sums volumes but hides position/CTR with the no-blending caption when sources were mixed", async () => {
    seoRouter({
      combined: overview({
        source: "combined",
        metrics: { clicks: 500, impressions: 12000, ctr: null, avg_position: null },
        deltas: { clicks: 40, impressions: 900, ctr: null, avg_position: null },
        position_sources: "bing+gsc",
      }),
    });
    const user = userEvent.setup();
    render(<SeoPage />);

    await screen.findByText("Avg position");
    await user.click(screen.getByRole("button", { name: "Combined" }));

    expect(await screen.findByText("Position hidden — Google and Bing report differently")).toBeInTheDocument();
    expect(screen.getAllByText("—").length).toBeGreaterThan(0);
    expect(screen.getByText("500")).toBeInTheDocument(); // clicks still sum
  });

  it("shows the Bing connect CTA — not a bare empty state — when Bing has no data", async () => {
    seoRouter();
    const user = userEvent.setup();
    render(<SeoPage />);

    await screen.findByText("Avg position");
    await user.click(screen.getByRole("button", { name: "Bing" }));

    expect(await screen.findByText("No Bing data yet")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Connect Bing Webmaster" })).toBeInTheDocument();
  });

  it("surfaces a failed overview fetch as an error state with retry, whatever the source", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      String(path).includes("/overview") ? Promise.reject(new Error("Server exploded")) : Promise.resolve({ weekly: [] }),
    );
    render(<SeoPage />);

    expect(await screen.findByText("Server exploded")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
