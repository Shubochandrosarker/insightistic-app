import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 5 workstream F: the Search Intelligence explorers (queries / pages /
 * opportunities). Mocking mirrors tests/traffic-page.test.tsx — the API layer
 * is stubbed, the real components render. Pins: row metrics (deltas + CTR),
 * cursor-based Load more, the error-over-empty rule, and doc 10's acceptance
 * criterion that flagged opportunities explain why.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
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

import QueriesPage from "@/app/dashboard/seo/queries/page";
import OpportunitiesPage from "@/app/dashboard/seo/opportunities/page";

function queriesResponse(rows: any[], hasMore = false, cursor: string | null = null) {
  return { data: rows, meta: { next_cursor: cursor, has_more: hasMore, window: {}, total_queries: rows.length } };
}

const sampleRows = [
  {
    query: "best running shoes",
    clicks: 120,
    impressions: 3800,
    ctr: 0.0316,
    position: 4.2,
    prev_clicks: 115,
    prev_impressions: 3600,
    prev_position: 5.1,
    clicks_delta: 5,
    impressions_delta: 200,
    position_delta: -0.9,
  },
  {
    query: "trail shoes sale",
    clicks: 40,
    impressions: 1500,
    ctr: 0.0267,
    position: 9.8,
    prev_clicks: 52,
    prev_impressions: 1400,
    prev_position: 8.4,
    clicks_delta: -12,
    impressions_delta: 100,
    position_delta: 1.4,
  },
];

const extraRow = {
  query: "waterproof hiking boots",
  clicks: 10,
  impressions: 500,
  ctr: 0.02,
  position: 12,
  prev_clicks: null,
  prev_impressions: null,
  prev_position: null,
  clicks_delta: null,
  impressions_delta: null,
  position_delta: null,
};

function respondWithQueries(first: any, cursorPage: any = null) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/search/queries")) {
      if (p.includes("cursor=") && cursorPage) return Promise.resolve(cursorPage);
      return Promise.resolve(first);
    }
    return Promise.resolve({ states: [] });
  });
}

describe("Queries explorer", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders rows with deltas, CTR% and previous-window sub-values, and offers Load more when has_more", async () => {
    respondWithQueries(queriesResponse(sampleRows, true, "cur-2"));
    render(<QueriesPage />);

    // ResponsiveTable keeps desktop table + mobile cards in the DOM (hidden by
    // CSS), so row content matches twice — assert against all matches.
    expect((await screen.findAllByText("best running shoes")).length).toBeGreaterThan(0);
    // CTR is a 0–1 ratio from the API, displayed as a percentage.
    expect(screen.getAllByText("3.2%").length).toBeGreaterThan(0);
    // Signed deltas with the up/down arrows (clicks up, clicks down).
    expect(screen.getAllByText("↑ 5").length).toBeGreaterThan(0);
    expect(screen.getAllByText("↓ 12").length).toBeGreaterThan(0);
    // Position deltas invert the tone, not the arrow: -0.9 (moving up the SERP)
    // renders a ↓ arrow but in the green "good" tone.
    expect(screen.getAllByText("↓ 0.9")[0]).toHaveClass("text-good");
    expect(screen.getAllByText("↑ 1.4")[0]).toHaveClass("text-bad");
    // Previous window values as muted sub-text.
    expect(screen.getAllByText("← 115").length).toBeGreaterThan(0);
    // has_more lands via an effect one commit after the rows — wait for it.
    expect(await screen.findByRole("button", { name: "Load more" })).toBeInTheDocument();
  });

  it("shows the error state — not the empty state — when the fetch fails", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      String(path).includes("/search/queries") ? Promise.reject(new Error("Server exploded")) : Promise.resolve({ states: [] }),
    );
    render(<QueriesPage />);

    expect(await screen.findByText("Server exploded")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
    expect(screen.queryByText("No query data for this window — sync GSC or widen the window.")).not.toBeInTheDocument();
  });

  it("appends the next cursor page when Load more is clicked", async () => {
    const user = userEvent.setup();
    respondWithQueries(queriesResponse(sampleRows, true, "cur-2"), queriesResponse([extraRow], false, null));
    render(<QueriesPage />);

    await screen.findAllByText("best running shoes");
    await user.click(await screen.findByRole("button", { name: "Load more" }));

    // The appended rows and has_more=false land in the same commit.
    expect((await screen.findAllByText("waterproof hiking boots")).length).toBeGreaterThan(0);
    expect(screen.queryByRole("button", { name: "Load more" })).not.toBeInTheDocument();
  });

  it("renders the empty copy when the window has no rows", async () => {
    respondWithQueries(queriesResponse([]));
    render(<QueriesPage />);

    expect(await screen.findByText("No query data for this window — sync GSC or widen the window.")).toBeInTheDocument();
  });
});

describe("Opportunities page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders a winner with its reason string (doc 10: flags explain why)", async () => {
    apiMocks.apiGet.mockImplementation((path: string) => {
      if (String(path).includes("/search/opportunities")) {
        return Promise.resolve({
          winners: [
            {
              query: "email marketing tool",
              reason: "Clicks up 40% while impressions held steady",
              clicks: 140,
              impressions: 1200,
              ctr: 0.117,
              position: 3.1,
              clicks_delta: 40,
              position_delta: -0.8,
            },
          ],
          losers: [],
          striking_distance: [],
          ctr_opportunities: [],
          content_decay: [],
          cannibalization: [],
        });
      }
      return Promise.resolve({ states: [] });
    });
    render(<OpportunitiesPage />);

    expect((await screen.findAllByText("email marketing tool")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Clicks up 40% while impressions held steady").length).toBeGreaterThan(0);
    expect(screen.getByRole("button", { name: "Export CSV" })).toBeInTheDocument();
  });

  it("shows the good-news copy when a tab has nothing flagged", async () => {
    apiMocks.apiGet.mockImplementation(() =>
      Promise.resolve({
        winners: [],
        losers: [],
        striking_distance: [],
        ctr_opportunities: [],
        content_decay: [],
        cannibalization: [],
      }),
    );
    render(<OpportunitiesPage />);

    expect(await screen.findByText("Nothing flagged in this window — that's good news.")).toBeInTheDocument();
  });
});
