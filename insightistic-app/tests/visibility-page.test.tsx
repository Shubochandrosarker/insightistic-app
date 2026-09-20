import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 11 workstream UI (AI Visibility, roadmap doc 14). Mocking mirrors
 * tests/traffic-page.test.tsx — the API layer is stubbed, the real page
 * renders. Pins: the rate KPIs with their exact-formula tooltips (doc 14:
 * "UI can explain exactly how each visibility metric is calculated"), the
 * per-provider chips (never blended), the prompts table's brand-mentioned
 * badges, the add-prompt POST, the ai_visibility_quota_reached 422 upgrade
 * copy, the competitor add/delete round-trip, and the first-prompt empty
 * state.
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
  }),
}));

import VisibilityPage from "@/app/dashboard/visibility/page";

const daysAgo = (n: number) => new Date(Date.now() - n * 86_400_000).toISOString();

const definitions = {
  mention_rate:
    "successful runs with the brand mentioned / eligible (successful) runs x 100 (doc 14: runs with brand mention / eligible runs)",
  citation_rate:
    "successful runs with at least one owned-domain citation / eligible (successful) runs x 100 (doc 14: runs citing owned domain / eligible runs)",
  avg_owned_citations_per_run: "total owned-domain citations / eligible (successful) runs",
  competitor_share:
    "successful runs where a competitor is mentioned AND the brand is not / eligible (successful) runs x 100 (doc 14 share-of-voice, loss framing)",
};

function visibilityPayload(overrides: Record<string, any> = {}) {
  return {
    metrics: {
      days: 30,
      prompts_tracked: 2,
      runs_total: 14,
      runs_failed: 2,
      mention_rate: 42.9,
      citation_rate: 12.5,
      avg_owned_citations_per_run: 1.4,
      competitor_share: 7.1,
      by_provider: [{ provider: "openai", runs: 9, mention_rate: 55.6 }],
      trend: [
        { date: daysAgo(2).slice(0, 10), runs: 4, mentions: 2 },
        { date: daysAgo(1).slice(0, 10), runs: 5, mentions: 3 },
      ],
      metric_definitions: definitions,
    },
    prompts: [
      {
        id: 11,
        prompt: "best WordPress booking plugin",
        category: "bookings",
        active: true,
        last_run_at: daysAgo(2),
        latest_run: { id: 5, status: "success", brand_mentioned: true, provider: "openai", checked_at: daysAgo(2) },
      },
      {
        id: 12,
        prompt: "best WooCommerce analytics plugin",
        category: null,
        active: true,
        last_run_at: daysAgo(3),
        latest_run: { id: 6, status: "success", brand_mentioned: false, provider: "perplexity", checked_at: daysAgo(3) },
      },
    ],
    competitors: [{ id: 7, name: "RivalRank", aliases: ["rivalrank.com"] }],
    quota: { key: "ai_prompt_runs_monthly", used: 12, limit: 100 },
    ...overrides,
  };
}

function mockOverview(payload: any) {
  apiMocks.apiGet.mockImplementation((path: string) =>
    String(path).includes("/ai-visibility") ? Promise.resolve(payload) : Promise.resolve({}),
  );
}

describe("AI Visibility page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the rate KPIs with their exact-formula tooltips and unblended provider chips", async () => {
    mockOverview(visibilityPayload());
    render(<VisibilityPage />);

    expect(await screen.findByText("42.9%")).toBeInTheDocument();
    expect(screen.getByText("12.5%")).toBeInTheDocument();
    expect(screen.getByText("7.1%")).toBeInTheDocument();
    expect(screen.getByText("1.4")).toBeInTheDocument();
    expect(screen.getByText("14 / 2")).toBeInTheDocument();

    // Doc 14 acceptance: the UI explains exactly how each metric is calculated.
    expect(screen.getByTitle(definitions.mention_rate)).toBeInTheDocument();
    expect(screen.getByTitle(definitions.competitor_share)).toBeInTheDocument();

    // Provider coverage is reported per provider — never one blended number.
    expect(screen.getByText("openai · 9 runs · 55.6% mentions")).toBeInTheDocument();
  });

  it("renders the prompts table with brand-mentioned badges, provider and category", async () => {
    mockOverview(visibilityPayload());
    render(<VisibilityPage />);

    // Desktop table and mobile cards both render — assert on any instance.
    expect((await screen.findAllByText("best WordPress booking plugin")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Mentioned").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Not mentioned").length).toBeGreaterThan(0);
    expect(screen.getAllByText("bookings").length).toBeGreaterThan(0);
    expect(screen.getAllByText("openai").length).toBeGreaterThan(0);
    expect(screen.getAllByText("perplexity").length).toBeGreaterThan(0);
  });

  it("adds a prompt through the form and reloads the overview", async () => {
    mockOverview(visibilityPayload());
    apiMocks.apiPost.mockResolvedValue({ prompt: {} });
    const user = userEvent.setup();
    render(<VisibilityPage />);

    await user.click(await screen.findByRole("button", { name: "Add prompt" }));
    await user.type(screen.getByLabelText("Prompt to track"), "best seo plugin for wordpress");
    await user.type(screen.getByLabelText("Category (optional)"), "seo");
    await user.click(screen.getByRole("button", { name: "Track prompt" }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/ai-visibility/prompts", {
      prompt: "best seo plugin for wordpress",
      category: "seo",
    });
    await waitFor(() =>
      expect(apiMocks.apiGet.mock.calls.filter(([p]) => String(p).includes("/ai-visibility")).length).toBeGreaterThanOrEqual(2),
    );
  });

  it("shows the upgrade copy when a run-now hits the ai_visibility_quota_reached 422", async () => {
    mockOverview(visibilityPayload());
    apiMocks.apiPost.mockRejectedValue({
      status: 422,
      data: {
        code: "ai_visibility_quota_reached",
        limit: { key: "ai_prompt_runs_monthly", max: 100, used: 100 },
        message: "Your plan limit for AI visibility prompt runs has been reached. Upgrade to continue.",
      },
    });
    const user = userEvent.setup();
    render(<VisibilityPage />);

    const runButtons = await screen.findAllByRole("button", { name: "Run now" });
    await user.click(runButtons[0]);

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/ai-visibility/prompts/11/check", {});
    expect(await screen.findByText(/100 of 100 runs this month/)).toBeInTheDocument();
    expect(screen.getByText(/upgrade your plan to keep checking/i)).toBeInTheDocument();
  });

  it("adds and deletes competitors", async () => {
    mockOverview(visibilityPayload());
    vi.spyOn(window, "confirm").mockReturnValue(true);
    apiMocks.apiPost.mockResolvedValue({ competitor: {} });
    apiMocks.apiDelete.mockResolvedValue({ ok: true });
    const user = userEvent.setup();
    render(<VisibilityPage />);

    await user.type(await screen.findByLabelText("Competitor name"), "NewRival");
    await user.click(screen.getByRole("button", { name: "Add competitor" }));
    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/ai-visibility/competitors", { name: "NewRival" });

    const li = screen.getByText("RivalRank").closest("li")!;
    await user.click(within(li).getByRole("button", { name: "Delete" }));
    expect(apiMocks.apiDelete).toHaveBeenCalledWith("/sites/1/ai-visibility/competitors/7");
  });

  it("invites the first prompt instead of rendering a wall of zeros on a fresh site", async () => {
    const empty = visibilityPayload();
    empty.metrics = { ...empty.metrics, prompts_tracked: 0, runs_total: 0, runs_failed: 0, by_provider: [], trend: [] };
    empty.prompts = [];
    mockOverview(empty);
    render(<VisibilityPage />);

    expect(await screen.findByText("Track your first AI visibility prompt")).toBeInTheDocument();
    expect(screen.getByText(/check whether they mention YOUR brand/)).toBeInTheDocument();
    // The add form is open right in the empty state so the fix is one keystroke away.
    expect(screen.getByLabelText("Prompt to track")).toBeInTheDocument();
  });
});
