import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 8 change monitoring UI. Mocking mirrors tests/traffic-page.test.tsx —
 * the API layer is stubbed, the real page renders. Pins: severity badges with
 * the human field labels, the Old → New cell (em-dash for a null previous
 * value), the GSC impressions impact column, the severity filter passing
 * through to the fetch, the doc-12 explanation copy, and the empty state.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
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

import ChangesPage from "@/app/dashboard/audit/changes/page";

const doneRun = {
  id: 12,
  status: "completed",
  trigger: "manual",
  started_at: new Date(Date.now() - 3_600_000).toISOString(),
  completed_at: new Date(Date.now() - 3_500_000).toISOString(),
  max_pages: 500,
  pages_discovered: 320,
  pages_crawled: 310,
  issues_found: 2,
};

function list(rows: any[], cursor: string | null = null, hasMore = false) {
  return { data: rows, meta: { next_cursor: cursor, has_more: hasMore, total: rows.length } };
}

const changeRows = [
  {
    url: "https://example.com/pricing",
    field: "title",
    severity: "critical",
    old_value: "Old pricing title",
    new_value: "New pricing title",
    impressions: 4200,
    created_at: "2026-09-19T10:00:00Z",
  },
  {
    url: "https://example.com/about",
    field: "meta_description",
    severity: "medium",
    old_value: null,
    new_value: "Freshened description",
    impressions: 0,
    created_at: "2026-09-19T10:00:00Z",
  },
];

function mockApi(changes: any = list(changeRows)) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/crawl/runs")) return Promise.resolve([doneRun]);
    if (p.includes("/crawl/changes")) return Promise.resolve(changes);
    return Promise.resolve({});
  });
}

describe("Audit changes page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders severity badges, human field labels, old→new values and the GSC impressions impact column", async () => {
    mockApi();
    render(<ChangesPage />);

    // Old → New cell content, including the null previous value as "—".
    expect((await screen.findAllByText("New pricing title")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Old pricing title").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Freshened description").length).toBeGreaterThan(0);
    expect(screen.getAllByText("—").length).toBeGreaterThan(0);

    // Human field labels — never the raw codes.
    expect(screen.getAllByText("Meta description").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Title").length).toBeGreaterThan(0);

    // Severity badges (desktop + mobile renders) and the impact column.
    expect(screen.getAllByText("Critical").length).toBeGreaterThanOrEqual(2);
    expect(screen.getAllByText("Medium").length).toBeGreaterThanOrEqual(2);
    expect(screen.getAllByText("GSC impressions").length).toBeGreaterThan(0);
    expect(screen.getAllByText("4,200").length).toBeGreaterThan(0);

    // The doc-12 explanation line about what changes compare.
    expect(
      screen.getByText(/Critical changes \(indexability loss, status errors\) also raise alerts/),
    ).toBeInTheDocument();
  });

  it("passes the severity filter through to the changes fetch", async () => {
    mockApi();
    const user = userEvent.setup();
    render(<ChangesPage />);

    await screen.findAllByText("New pricing title");

    await user.selectOptions(screen.getByLabelText("Change severity"), "high");

    const calls = apiMocks.apiGet.mock.calls.filter(([p]) => String(p).includes("/crawl/changes"));
    expect(calls.some(([p]) => String(p).includes("severity=high"))).toBe(true);
    // Changes are scoped to the latest completed run, with the audit per_page.
    expect(calls.some(([p]) => String(p).includes("run_id=12") && String(p).includes("per_page=50"))).toBe(true);
  });

  it("shows the empty copy when the latest run detected no changes", async () => {
    mockApi(list([]));
    render(<ChangesPage />);

    expect(await screen.findByText("No changes detected in the latest run.")).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Load more" })).not.toBeInTheDocument();
  });
});
