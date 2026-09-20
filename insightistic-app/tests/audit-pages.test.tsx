import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 7 workstream UI (Site Audit). Mocking mirrors tests/traffic-page.test.tsx —
 * the API layer is stubbed, the real pages render. Pins: the health score with
 * its breakdown (doc 12: never a mysterious single number), the first-crawl
 * CTA for never-crawled sites, page status tones + the error filter, the
 * issue Ignore lifecycle POST, and the 409 already-running copy on run-now.
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

import AuditOverviewPage from "@/app/dashboard/audit/page";
import AuditPagesPage from "@/app/dashboard/audit/pages/page";
import AuditLinksPage from "@/app/dashboard/audit/links/page";
import AuditIssuesPage from "@/app/dashboard/audit/issues/page";

const doneRun = {
  id: 12,
  status: "completed",
  trigger: "manual",
  started_at: new Date(Date.now() - 3_600_000).toISOString(),
  completed_at: new Date(Date.now() - 3_500_000).toISOString(),
  max_pages: 500,
  pages_discovered: 320,
  pages_crawled: 310,
  issues_found: 4,
};

const health = {
  score: 82,
  breakdown: {
    indexability: { open_issues: 3, penalty: 6, weight: 30 },
    content: { open_issues: 1, penalty: 2, weight: 20 },
  },
  top_issues: [{ id: 7, issue_code: "missing_title", severity: "high", target_url: "https://example.com/about" }],
};

function list(rows: any[], cursor: string | null = null, hasMore = false) {
  return { data: rows, meta: { next_cursor: cursor, has_more: hasMore, total: rows.length } };
}

function mockApi(opts: { runs?: any[]; health?: any; pages?: any; links?: any; issues?: any } = {}) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/crawl/runs")) return Promise.resolve(opts.runs ?? [doneRun]);
    if (p.includes("/crawl/health")) return Promise.resolve(opts.health ?? health);
    if (p.includes("/crawl/pages")) return Promise.resolve(opts.pages ?? list([]));
    if (p.includes("/crawl/links")) return Promise.resolve(opts.links ?? list([]));
    if (p.includes("/crawl/issues")) return Promise.resolve(opts.issues ?? list([]));
    return Promise.resolve({});
  });
}

describe("Audit overview", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the health score with its per-category breakdown and top issues", async () => {
    mockApi();
    render(<AuditOverviewPage />);

    // The big score number, the "/ 100" scale, and a weighted category row.
    expect(await screen.findByText("82")).toBeInTheDocument();
    expect(screen.getByText("/ 100")).toBeInTheDocument();
    expect(screen.getAllByText("Indexability").length).toBeGreaterThan(0);
    expect(screen.getAllByText(/30% weight/).length).toBeGreaterThan(0);
    // Doc 12: top issues are surfaced with human labels, not raw codes.
    expect(screen.getAllByText("Missing title").length).toBeGreaterThan(0);
  });

  it("shows the first-crawl CTA — not empty zeros — for a never-crawled site", async () => {
    mockApi({ runs: [] });
    render(<AuditOverviewPage />);

    expect(await screen.findByText("Run your first crawl")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Run first crawl" })).toBeInTheDocument();
    // No runs, no health request — there is nothing to score yet.
    expect(apiMocks.apiGet.mock.calls.filter(([p]) => String(p).includes("/crawl/health"))).toHaveLength(0);
  });

  it("surfaces the already-running copy when run-now hits a 409", async () => {
    mockApi();
    apiMocks.apiPost.mockRejectedValue({ status: 409, data: { code: "crawl_already_running" } });
    const user = userEvent.setup();
    render(<AuditOverviewPage />);

    await user.click(await screen.findByRole("button", { name: "Run crawl now" }));

    expect(await screen.findByText("A crawl is already running.")).toBeInTheDocument();
    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/crawl/run", {});
  });
});

describe("Crawled pages explorer", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders rows with status tones and passes status=error when the filter changes", async () => {
    mockApi({
      pages: list([
        {
          url: "https://example.com/broken-page",
          status_code: 404,
          indexable: false,
          title: null,
          word_count: 0,
          h1_count: 0,
          response_ms: 320,
        },
        {
          url: "https://example.com/fine",
          status_code: 200,
          indexable: true,
          title: "Fine page",
          word_count: 900,
          h1_count: 1,
          response_ms: 120,
        },
      ]),
    });
    const user = userEvent.setup();
    render(<AuditPagesPage />);

    expect((await screen.findAllByText("/broken-page")).length).toBeGreaterThan(0);
    // 4xx status codes get the danger tone; the indexable flag renders as a badge.
    expect(screen.getAllByText("404")[0]).toHaveClass("text-bad");
    expect(screen.getAllByText("No").length).toBeGreaterThan(0);

    await user.selectOptions(screen.getByLabelText("Page status"), "error");

    await waitFor(() =>
      expect(
        apiMocks.apiGet.mock.calls.some(([p]) => String(p).includes("/crawl/pages") && String(p).includes("status=error")),
      ).toBe(true),
    );
  });
});

describe("Links explorer", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders broken links and switches the type tab to the redirect param", async () => {
    mockApi({
      links: list([
        { source_url: "https://example.com/home", target_url: "https://example.com/gone", status_code: 404, anchor: "Pricing", is_nofollow: false },
      ]),
    });
    const user = userEvent.setup();
    render(<AuditLinksPage />);

    expect((await screen.findAllByText("/gone")).length).toBeGreaterThan(0);
    expect(screen.getAllByText("Pricing").length).toBeGreaterThan(0);

    await user.click(screen.getByRole("button", { name: "Redirecting" }));

    await waitFor(() =>
      expect(apiMocks.apiGet.mock.calls.some(([p]) => String(p).includes("/crawl/links") && String(p).includes("type=redirect"))).toBe(true),
    );
  });
});

describe("Issue explorer", () => {
  beforeEach(() => vi.clearAllMocks());

  it("posts status=ignored when Ignore is clicked on an open issue", async () => {
    mockApi({
      issues: list([
        {
          id: 7,
          issue_code: "missing_title",
          severity: "high",
          category: "onpage",
          target_url: "https://example.com/about",
          first_seen_at: new Date(Date.now() - 86_400_000).toISOString(),
          last_seen_at: new Date(Date.now() - 3_600_000).toISOString(),
        },
      ]),
    });
    apiMocks.apiPost.mockResolvedValue({});
    const user = userEvent.setup();
    render(<AuditIssuesPage />);

    // ResponsiveTable renders desktop + mobile layouts — click the first action.
    const ignoreButtons = await screen.findAllByRole("button", { name: "Ignore" });
    await user.click(ignoreButtons[0]);

    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/crawl/issues/7/status", { status: "ignored" }),
    );
    // The lifecycle write reloads the open list.
    await waitFor(() =>
      expect(apiMocks.apiGet.mock.calls.filter(([p]) => String(p).includes("/crawl/issues")).length).toBeGreaterThanOrEqual(2),
    );
  });

  it("shows the graceful permission message when the lifecycle write is forbidden", async () => {
    mockApi({
      issues: list([
        {
          id: 7,
          issue_code: "thin_content",
          severity: "medium",
          category: "onpage",
          target_url: "https://example.com/tag/x",
          first_seen_at: null,
          last_seen_at: null,
        },
      ]),
    });
    apiMocks.apiPost.mockRejectedValue({ status: 403, message: "Forbidden" });
    const user = userEvent.setup();
    render(<AuditIssuesPage />);

    const ignoreButtons = await screen.findAllByRole("button", { name: "Ignore" });
    await user.click(ignoreButtons[0]);

    expect(
      await screen.findByText("You don't have permission to change issue status — ask a site owner or admin."),
    ).toBeInTheDocument();
  });
});
