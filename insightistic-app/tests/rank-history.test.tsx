import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";

/**
 * Phase 10 workstream UI — keyword history detail. Pins: the inverted-axis
 * chart label, the doc-13 separation label between tracker positions and GSC
 * impressions, observation rows with an explicit "—" for never-found checks,
 * and the not-found state when the id isn't in the tracker list.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

vi.mock("next/navigation", () => ({
  useParams: () => ({ keyword: "11" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
  usePathname: () => "/dashboard/rank/11",
  useSearchParams: () => new URLSearchParams(),
}));

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({
    siteId: 1,
    sites: [{ id: 1, name: "Test Shop" }],
    period: "last_30_days",
    rangeQuery: "",
  }),
}));

import RankKeywordHistoryPage from "@/app/dashboard/rank/[keyword]/page";

const kwRow = {
  id: 11,
  keyword: "best running shoes",
  engine: "google",
  device: "desktop",
  country: "US",
  location: null,
  last_checked_at: "2026-09-20T10:00:00Z",
  latest: { position: 3, ranking_url: "https://example.com/shoes", checked_at: "2026-09-20T10:00:00Z" },
};

const history = [
  { checked_at: "2026-09-18T10:00:00Z", position: 5, ranking_url: "https://example.com/shoes", cost_units: 0.02 },
  { checked_at: "2026-09-19T10:00:00Z", position: null, ranking_url: null, cost_units: 0.02 },
  { checked_at: "2026-09-20T10:00:00Z", position: 3, ranking_url: "https://example.com/shoes", cost_units: 0.02 },
];

function mockApi(opts: { list?: any[] } = {}) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/history")) return Promise.resolve({ keyword: kwRow, history });
    return Promise.resolve({
      provider_configured: true,
      kpis: {},
      keywords: { data: opts.list ?? [kwRow], meta: { next_cursor: null, has_more: false, total: (opts.list ?? [kwRow]).length } },
    });
  });
}

describe("Rank keyword history page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the inverted chart label, the tracker-vs-GSC separation, and observation rows", async () => {
    mockApi();
    render(<RankKeywordHistoryPage />);

    // Chart axis is inverted so up = better; the label says so explicitly.
    expect(await screen.findByText("Position (inverted — top = #1)")).toBeInTheDocument();
    // Doc 13: tracker positions are labeled separately from GSC impressions.
    expect(screen.getByText("SERP positions (tracker)")).toBeInTheDocument();
    expect(screen.getByText(/Separate from GSC impressions/)).toBeInTheDocument();
    // Header identity: keyword + engine/device/country chips + back link.
    expect(screen.getByRole("heading", { name: "best running shoes" })).toBeInTheDocument();
    expect(screen.getAllByText("google").length).toBeGreaterThan(0);
    expect(screen.getAllByText("desktop").length).toBeGreaterThan(0);
    expect(screen.getAllByText("US").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Back to Rank Tracker").length).toBeGreaterThan(0);
  });

  it("renders null-position observations as an explicit not-ranking dash", async () => {
    mockApi();
    render(<RankKeywordHistoryPage />);

    // Position #3 appears in header + 2 observations (desktop + mobile layouts)…
    expect((await screen.findAllByText("#3")).length).toBeGreaterThanOrEqual(2);
    // …and the never-found check is a titled dash, never a blank cell.
    const dashes = screen.getAllByTitle("Not ranking");
    expect(dashes.length).toBeGreaterThanOrEqual(2);
  });

  it("shows the not-found state when the keyword is missing from the tracker list", async () => {
    mockApi({ list: [] });
    render(<RankKeywordHistoryPage />);

    expect(await screen.findByText("Keyword not found")).toBeInTheDocument();
    expect(screen.getAllByText("Back to Rank Tracker").length).toBeGreaterThan(0);
  });
});
