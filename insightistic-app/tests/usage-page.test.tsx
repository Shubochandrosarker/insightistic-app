import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Usage dashboard (Phase 15): strictly payload-driven. It renders whatever
 * GET /billing/subscription reports in `usage` (ai_insights + reports today),
 * shows coming-soon rows ONLY for dimensions the payload lacks, surfaces the
 * lifetime grant when present, and renders an error state instead of zeros
 * when the API fails.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

import UsagePage from "@/app/dashboard/usage/page";

const payload = {
  organization: { status: "active" },
  plan: { name: "Growth", site_limit: 10 },
  usage: {
    ai_insights: { used: 2, limit: 75 },
    reports: { used: 1, limit: null },
  },
  subscription: { billing_provider: "paddle" },
};

function mockSubscription(data: any) {
  apiMocks.apiGet.mockImplementation((path: string) =>
    path === "/billing/subscription" ? Promise.resolve(data) : Promise.resolve({}),
  );
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("Usage page", () => {
  it("renders a usage bar for every dimension in the payload, ∞ for null limits", async () => {
    mockSubscription(payload);

    render(<UsagePage />);

    expect(await screen.findByText("Standard AI requests")).toBeInTheDocument();
    expect(screen.getByText("2 / 75")).toBeInTheDocument();
    expect(screen.getByText("Reports")).toBeInTheDocument();
    // null limit renders as ∞, never as a 0-limit bar.
    expect(screen.getByText("1 / ∞")).toBeInTheDocument();
    expect(screen.getByText("This billing month")).toBeInTheDocument();
  });

  it("renders real meters for tracked keywords, crawl pages and AI prompt runs with no dev copy", async () => {
    mockSubscription({
      ...payload,
      usage: {
        ...payload.usage,
        tracked_keywords: { used: 7, limit: 150 },
        crawl_pages: { used: 12000, limit: 50000 },
        ai_prompt_runs: { used: 3, limit: 40 },
      },
    });

    render(<UsagePage />);

    expect(await screen.findByText("Crawl pages")).toBeInTheDocument();
    expect(screen.getByText("12,000 / 50,000")).toBeInTheDocument();
    expect(screen.getByText("Tracked keywords")).toBeInTheDocument();
    expect(screen.getByText("7 / 150")).toBeInTheDocument();
    expect(screen.getByText("AI prompt runs")).toBeInTheDocument();
    expect(screen.getByText("3 / 40")).toBeInTheDocument();
    expect(screen.queryByText("Coming to usage tracking")).not.toBeInTheDocument();
    expect(screen.queryByText("Coming soon")).not.toBeInTheDocument();
    expect(screen.queryByText(/server-side/)).not.toBeInTheDocument();
  });

  it("shows the lifetime grant card when the subscription payload carries one", async () => {
    mockSubscription({
      ...payload,
      subscription: { billing_provider: "paddle", lifetime_grant: { active: true, plan_slug: "founder_tier_2" } },
    });

    render(<UsagePage />);

    expect(await screen.findByText("Lifetime access")).toBeInTheDocument();
    expect(screen.getByText("Founder Tier 2 lifetime")).toBeInTheDocument();
  });

  it("renders an error state with retry when the subscription fetch fails", async () => {
    apiMocks.apiGet.mockImplementation(() => Promise.reject(new Error("Network down")));

    render(<UsagePage />);

    expect(await screen.findByText("Something went wrong")).toBeInTheDocument();
    expect(screen.getByText("Network down")).toBeInTheDocument();
    expect(screen.queryByText("This billing month")).not.toBeInTheDocument();

    // Retry re-fetches and recovers into the ready state.
    mockSubscription(payload);
    await userEvent.click(screen.getByRole("button", { name: "Try again" }));
    await waitFor(() => expect(screen.getByText("This billing month")).toBeInTheDocument());
  });
});
