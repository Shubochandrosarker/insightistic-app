import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Insight lifecycle controls (spec Phase 6).
 *
 * The behaviours worth pinning are the ones a refactor would quietly break:
 * the view is driven by the server (so counts and rows can't disagree),
 * dismissing and saving hit the right verb, and "Create task" produces
 * something the user can actually take away.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ siteId: 1, sites: [{ id: 1, name: "Test Shop" }], period: "last_30_days" }),
}));

import InsightsPage from "@/app/dashboard/insights/page";

const liveInsight = {
  id: 7,
  type: "traffic",
  title: "Sessions fell 12%",
  summary: "Organic sessions dropped week over week.",
  recommendation: "Review the pages that lost impressions.",
  severity: "medium",
  priority_score: 5,
  status: "unread",
  saved: false,
  dismissed: false,
  actioned: false,
  stale: false,
  expires_at: "2026-07-26T00:00:00+00:00",
  created_at: "2026-07-25T00:00:00+00:00",
};

function respond(insights: any[], counts = { live: insights.length, saved: 0, dismissed: 0 }) {
  apiMocks.apiGet.mockResolvedValue({ insights, counts });
}

describe("Insights page lifecycle", () => {
  beforeEach(() => vi.clearAllMocks());

  it("requests the live view by default and shows the counts", async () => {
    respond([liveInsight], { live: 1, saved: 2, dismissed: 3 });
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("Sessions fell 12%")).toBeInTheDocument());
    expect(apiMocks.apiGet).toHaveBeenCalledWith("/sites/1/insights?filter=live");

    // Counts come from the server, so a stale local tally can't drift.
    expect(screen.getByRole("button", { name: /Dismissed 3/ })).toBeInTheDocument();
  });

  it("switches to the saved view server-side rather than filtering locally", async () => {
    respond([liveInsight]);
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("Sessions fell 12%")).toBeInTheDocument());

    await userEvent.click(screen.getByRole("button", { name: /^Saved/ }));

    await waitFor(() =>
      expect(apiMocks.apiGet).toHaveBeenCalledWith("/sites/1/insights?filter=saved"),
    );
  });

  it("dismisses with POST", async () => {
    respond([liveInsight]);
    apiMocks.apiPost.mockResolvedValue({ insight: { ...liveInsight, dismissed: true } });
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("Sessions fell 12%")).toBeInTheDocument());
    await userEvent.click(screen.getByRole("button", { name: "Dismiss" }));

    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/insights/7/dismiss"),
    );
  });

  it("restores with DELETE on the same route — dismissal hides, it never deletes", async () => {
    respond([{ ...liveInsight, dismissed: true }], { live: 0, saved: 0, dismissed: 1 });
    apiMocks.apiDelete.mockResolvedValue({ insight: liveInsight });
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByRole("button", { name: "Restore" })).toBeInTheDocument());
    await userEvent.click(screen.getByRole("button", { name: "Restore" }));

    await waitFor(() =>
      expect(apiMocks.apiDelete).toHaveBeenCalledWith("/sites/1/insights/7/dismiss"),
    );
  });

  it("saves an unsaved insight and unsaves a saved one", async () => {
    respond([{ ...liveInsight, saved: true }], { live: 1, saved: 1, dismissed: 0 });
    apiMocks.apiDelete.mockResolvedValue({ insight: liveInsight });
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("Sessions fell 12%")).toBeInTheDocument());
    await userEvent.click(screen.getByRole("button", { name: /Saved$/ }));

    await waitFor(() => expect(apiMocks.apiDelete).toHaveBeenCalledWith("/sites/1/insights/7/save"));
  });

  it("marks a stale insight so nobody acts on old numbers as if they were current", async () => {
    respond([{ ...liveInsight, stale: true }]);
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("Stale")).toBeInTheDocument());
  });

  it("shows the task markdown returned by the server", async () => {
    respond([liveInsight]);
    apiMocks.apiPost.mockResolvedValue({
      task: {
        title: "Sessions fell 12%",
        markdown: "## Sessions fell 12%\n\n- [ ] Review lost impressions",
      },
      insight: { ...liveInsight, actioned: true },
    });
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("Sessions fell 12%")).toBeInTheDocument());
    await userEvent.click(screen.getByRole("button", { name: /Create task/ }));

    await waitFor(() => expect(screen.getByText(/Task ready to paste/)).toBeInTheDocument());
    expect(screen.getByText(/- \[ \] Review lost impressions/)).toBeInTheDocument();
  });

  it("surfaces a failed lifecycle call instead of pretending it worked", async () => {
    respond([liveInsight]);
    apiMocks.apiPost.mockRejectedValue({ status: 500, message: "Server exploded" });
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("Sessions fell 12%")).toBeInTheDocument());
    await userEvent.click(screen.getByRole("button", { name: "Dismiss" }));

    await waitFor(() => expect(screen.getByText("Server exploded")).toBeInTheDocument());
  });

  it("tells the user when there is nothing saved yet", async () => {
    respond([], { live: 0, saved: 0, dismissed: 0 });
    render(<InsightsPage />);

    await waitFor(() => expect(screen.getByText("No insights yet")).toBeInTheDocument());

    await userEvent.click(screen.getByRole("button", { name: /^Saved/ }));
    await waitFor(() => expect(screen.getByText("Nothing saved yet")).toBeInTheDocument());
  });
});
