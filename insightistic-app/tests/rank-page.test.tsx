import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 10 workstream UI (Rank Tracker, roadmap doc 13). Mocking mirrors
 * tests/traffic-page.test.tsx — the API layer is stubbed, the real page
 * renders. Pins: the KPI row, position tone ladder + inverted delta
 * semantics (a lower position number is an improvement), the
 * not-configured provider banner, the parsed add-keywords POST with its
 * created/skipped copy, the tracked-keyword quota envelope with upgrade
 * hint, and the same-day re-check guard.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
  apiDownload: vi.fn(),
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

import RankTrackerPage from "@/app/dashboard/rank/page";

const kpis = {
  tracked: 10,
  active: 8,
  top3: 2,
  top10: 5,
  top20: 7,
  not_found: 1,
  improved: 3,
  declined: 2,
  visibility: 64,
};

const yesterday = new Date(Date.now() - 26 * 3_600_000).toISOString();
const today = new Date().toISOString();

const keywords = [
  {
    id: 11,
    keyword: "best running shoes",
    engine: "google",
    device: "desktop",
    country: "US",
    location: null,
    last_checked_at: yesterday,
    latest: { position: 2, ranking_url: "https://example.com/shoes", checked_at: yesterday },
    previous_position: 5,
    best_position: 1,
    impressions_28d: 4200,
    cost_30d: 12.4,
  },
  {
    id: 12,
    keyword: "waterproof hiking boots",
    engine: "google",
    device: "mobile",
    country: "GB",
    location: null,
    last_checked_at: today,
    latest: { position: null, ranking_url: null, checked_at: today },
    previous_position: null,
    best_position: 14,
    impressions_28d: 300,
    cost_30d: 1.2,
  },
];

const suggestions = [{ query: "trail running shoes", impressions: 900, clicks: 40, tracked: false }];

function rankPayload(opts: { provider?: boolean; keywords?: any[] } = {}) {
  return {
    provider_configured: opts.provider ?? true,
    kpis,
    keywords: {
      data: opts.keywords ?? keywords,
      meta: { next_cursor: null, has_more: false, total: (opts.keywords ?? keywords).length },
    },
  };
}

function mockApi(opts: { provider?: boolean; keywords?: any[]; suggestions?: any[] } = {}) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/rank/suggestions")) return Promise.resolve(opts.suggestions ?? suggestions);
    if (p.includes("/rank")) return Promise.resolve(rankPayload(opts));
    return Promise.resolve({});
  });
}

describe("Rank Tracker page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the KPI row including the visibility score out of 100", async () => {
    mockApi();
    render(<RankTrackerPage />);

    expect(await screen.findByText("Tracked (active)")).toBeInTheDocument();
    for (const label of ["Top 3", "Top 10", "Top 20", "Improved", "Declined", "Not found", "Visibility"]) {
      expect(screen.getByText(label)).toBeInTheDocument();
    }
    expect(screen.getByText("64/100")).toBeInTheDocument();
    // top3/top10 count only checked keywords — the 8 active total is the Tracked tile.
    expect(screen.getByText("8")).toBeInTheDocument();
  });

  it("renders keyword rows with position tones and inverted delta semantics", async () => {
    mockApi();
    render(<RankTrackerPage />);

    // Position 2 lands in the 1-3 ladder: green + bold.
    const pos = await screen.findAllByText("#2");
    expect(pos[0]).toHaveClass("text-good", "font-bold");
    // 2 → 5 improved: delta -3 must read as GOOD despite the negative number.
    const delta = await screen.findAllByText("↓ 3");
    expect(delta[0]).toHaveClass("text-good");
    // Never-found keyword: an explicit not-ranking dash, not a blank cell.
    expect(screen.getAllByTitle("Not ranking").length).toBeGreaterThan(0);
    // Doc 13: GSC impressions carry their own column, labeled separately.
    expect(screen.getAllByText("GSC impr. 28d").length).toBeGreaterThan(0);
  });

  it("shows the provider banner when the SERP provider is not configured", async () => {
    mockApi({ provider: false });
    render(<RankTrackerPage />);

    expect(
      await screen.findByText(/DATAFORSEO_LOGIN \/ DATAFORSEO_PASSWORD/),
    ).toBeInTheDocument();
  });

  it("posts the parsed keyword list and reports created vs skipped", async () => {
    mockApi();
    apiMocks.apiPost.mockResolvedValue({ created: 2, skipped: 1 });
    const user = userEvent.setup();
    render(<RankTrackerPage />);

    await user.click(await screen.findByRole("button", { name: "Add keywords" }));
    await user.type(screen.getByLabelText("Keywords to track"), "alpha widget{Enter}  beta tool {Enter}");
    await user.click(screen.getByRole("button", { name: "Add 2 keywords" }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/rank/keywords", {
      keywords: [{ keyword: "alpha widget" }, { keyword: "beta tool" }],
    });
    expect(await screen.findByText("Added 2 (1 duplicates skipped)")).toBeInTheDocument();
  });

  it("renders the quota envelope with the upgrade hint when the keyword limit is reached", async () => {
    mockApi();
    apiMocks.apiPost.mockRejectedValue({
      status: 422,
      data: {
        code: "tracked_keyword_limit_reached",
        limit: { key: "tracked_keywords", max: 50, used: 50 },
        message: "Tracked keyword limit reached.",
      },
    });
    const user = userEvent.setup();
    render(<RankTrackerPage />);

    await user.click(await screen.findByRole("button", { name: "Add keywords" }));
    await user.type(screen.getByLabelText("Keywords to track"), "one more keyword");
    await user.click(screen.getByRole("button", { name: "Add 1 keyword" }));

    expect(await screen.findByText(/50 of 50 tracked keywords/)).toBeInTheDocument();
    expect(screen.getByText(/Upgrade your plan to track more keywords/)).toBeInTheDocument();
  });

  it("surfaces the provider message on the check-all 422 instead of a generic failure", async () => {
    mockApi({ provider: false });
    apiMocks.apiPost.mockRejectedValue({ status: 422, data: { code: "rank_provider_not_configured" } });
    const user = userEvent.setup();
    render(<RankTrackerPage />);

    await user.click(await screen.findByRole("button", { name: "Check all now" }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/rank/check", {});
    // The banner is already on screen; the 422 repeats the same message as the action status.
    const hits = await screen.findAllByText(/DATAFORSEO_LOGIN \/ DATAFORSEO_PASSWORD/);
    expect(hits.length).toBeGreaterThanOrEqual(2);
  });

  it("disables re-check for keywords already checked today", async () => {
    mockApi();
    render(<RankTrackerPage />);

    // Keyword 12 was checked today; keyword 11 yesterday is still re-checkable.
    const checkedToday = await screen.findAllByTitle("Already checked today");
    expect(checkedToday.length).toBeGreaterThan(0);
    expect((checkedToday[0] as HTMLButtonElement).disabled).toBe(true);
    const fresh = screen.getAllByTitle("Check position now");
    expect(fresh.length).toBeGreaterThan(0);
    expect((fresh[0] as HTMLButtonElement).disabled).toBe(false);
  });

  it("adds suggested keywords from Search Console in one batched confirm POST", async () => {
    mockApi();
    vi.spyOn(window, "confirm").mockReturnValue(true);
    apiMocks.apiPost.mockResolvedValue({ created: 1 });
    const user = userEvent.setup();
    render(<RankTrackerPage />);

    await user.click(await screen.findByRole("button", { name: /Add all/ }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/rank/suggestions/confirm", {
      queries: ["trail running shoes"],
    });
    await waitFor(() =>
      expect(apiMocks.apiGet.mock.calls.filter(([p]) => String(p).includes("/rank/suggestions")).length).toBeGreaterThanOrEqual(2),
    );
  });
});
