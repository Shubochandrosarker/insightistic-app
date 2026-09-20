import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * The audit that kicked off Phase 3 found the read-heavy pages swallowing API
 * errors: they destructured only {data, loading}, so a 500 rendered the exact
 * same "No traffic data yet" empty state as a genuinely empty site. Traffic is
 * the representative page — these tests pin the fix: a failed overview fetch
 * must show the error state (with retry), never the empty state.
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

import TrafficPage from "@/app/dashboard/traffic/page";

const emptyOverview = { metrics: {}, deltas: {}, series: [], has_data: false };

function respond(overview: any, rows: any[] = []) {
  apiMocks.apiGet.mockImplementation((path: string) =>
    path.includes("/overview") ? Promise.resolve(overview) : Promise.resolve({ rows }),
  );
}

function failOverview(message: string) {
  apiMocks.apiGet.mockImplementation((path: string) =>
    path.includes("/overview") ? Promise.reject(new Error(message)) : Promise.resolve({ rows: [] }),
  );
}

describe("Traffic page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the error state — not the empty state — when the overview fetch fails", async () => {
    failOverview("Server exploded");
    render(<TrafficPage />);

    expect(await screen.findByText("Server exploded")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
    // The old bug: an error looked identical to a site with no data.
    expect(screen.queryByText("No traffic data yet")).not.toBeInTheDocument();
  });

  it("retries the failed fetch and recovers", async () => {
    let failing = true;
    apiMocks.apiGet.mockImplementation((path: string) =>
      path.includes("/overview")
        ? failing
          ? Promise.reject(new Error("Server exploded"))
          : Promise.resolve(emptyOverview)
        : Promise.resolve({ rows: [] }),
    );
    const user = userEvent.setup();
    render(<TrafficPage />);

    await screen.findByText("Server exploded");

    failing = false;
    await user.click(screen.getByRole("button", { name: "Try again" }));

    expect(await screen.findByText("No traffic data yet")).toBeInTheDocument();
    const overviewCalls = apiMocks.apiGet.mock.calls.filter(([p]) => String(p).includes("/overview"));
    expect(overviewCalls.length).toBeGreaterThanOrEqual(2);
  });

  it("keeps the existing empty state when the fetch succeeds with no data", async () => {
    respond(emptyOverview);
    render(<TrafficPage />);

    expect(await screen.findByText("No traffic data yet")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Try again" })).not.toBeInTheDocument();
  });

  it("appends the pinned rangeQuery to fetch URLs", async () => {
    respond(emptyOverview);
    render(<TrafficPage />);

    await screen.findByText("No traffic data yet");
    const paths = apiMocks.apiGet.mock.calls.map(([p]) => String(p));
    expect(paths.some((p) => p.includes("/analytics/traffic/overview?period=last_30_days"))).toBe(true);
  });
});
