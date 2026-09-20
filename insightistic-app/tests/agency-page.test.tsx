import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, within } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 13 workstream UI (Agency Portfolio, roadmap doc 20). Mocking mirrors
 * tests/traffic-page.test.tsx — the API layer is stubbed, the real page
 * renders. Pins: the summary tiles + portfolio rows with health tones, the
 * client/health filter params on the portfolio fetch, add-client POSTing,
 * assign-sites POSTing the exact sync payload, delete confirm, and the
 * graceful 403 message for role-gated writes (owner/admin server-side).
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
    sites: [
      { id: 1, name: "Acme Store", domain: "acme.example" },
      { id: 2, name: "Beta Blog", domain: "beta.example" },
    ],
  }),
}));

import AgencyPortfolioPage from "@/app/dashboard/agency/page";

const hoursAgo = (n: number) => new Date(Date.now() - n * 3_600_000).toISOString();

const portfolioPayload = {
  organization_id: 4,
  filters: { client_id: null, health: null },
  summary: { sites: 3, avg_health: 61.5, sites_with_critical: 1, clients: 1 },
  sites: [
    {
      site_id: 1,
      site_name: "Acme Store",
      domain: "acme.example",
      client_id: 11,
      client_name: "Acme Co",
      health_score: 32,
      has_crawl_data: true,
      health_state: "critical",
      uptime: { availability_pct: 99.8, open_incidents: 1 },
      search: { clicks: 1200, impressions: 34000 },
      critical_issues: 3,
      freshness: { last_sync_at: hoursAgo(1), data_as_of: hoursAgo(2) },
    },
    {
      site_id: 2,
      site_name: "Beta Blog",
      domain: "beta.example",
      client_id: 11,
      client_name: "Acme Co",
      health_score: 91,
      has_crawl_data: true,
      health_state: "healthy",
      uptime: null,
      search: null,
      critical_issues: 0,
      freshness: { last_sync_at: hoursAgo(3), data_as_of: hoursAgo(4) },
    },
    {
      site_id: 3,
      site_name: "Gamma Wiki",
      domain: "gamma.example",
      client_id: null,
      client_name: null,
      health_score: 0,
      has_crawl_data: false,
      health_state: "unknown",
      uptime: null,
      search: null,
      critical_issues: 0,
      freshness: { last_sync_at: null, data_as_of: null },
    },
  ],
};

const clientsPayload = {
  clients: [{ id: 11, name: "Acme Co", contact_email: "owner@acme.example", notes: null, sites_count: 2 }],
};

function mockApi(portfolio: any = portfolioPayload, clients: any = clientsPayload) {
  apiMocks.apiGet.mockImplementation((path: string) => {
    const p = String(path);
    if (p.includes("/agency/clients")) return Promise.resolve(clients);
    if (p.includes("/agency/portfolio")) return Promise.resolve(portfolio);
    return Promise.resolve({});
  });
  apiMocks.apiPost.mockResolvedValue({ client_id: 11, site_ids: [1], sites_count: 1 });
  apiMocks.apiDelete.mockResolvedValue({});
}

describe("Agency portfolio page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.stubGlobal("confirm", vi.fn(() => true));
    mockApi();
  });

  it("renders the summary tiles and portfolio rows with health tones", async () => {
    render(<AgencyPortfolioPage />);

    // Tiles: sites / avg health (of the crawled subset) / critical / clients.
    expect(await screen.findByTestId("tile-sites")).toHaveTextContent("3");
    expect(screen.getByTestId("tile-health")).toHaveTextContent("62");
    expect(screen.getByTestId("tile-health")).toHaveTextContent("of 2 crawled");
    expect(screen.getByTestId("tile-critical")).toHaveTextContent("1");
    expect(screen.getByTestId("tile-clients")).toHaveTextContent("1");

    // Rows render with domain subtext (desktop table + mobile cards).
    expect(screen.getAllByText("Acme Store").length).toBeGreaterThan(0);
    expect(screen.getAllByText("acme.example").length).toBeGreaterThan(0);
    expect(screen.getAllByText("Gamma Wiki").length).toBeGreaterThan(0);

    // Health tones: critical badge, healthy badge, and "not crawled" for the
    // never-crawled site — "no data" never masquerades as a verdict.
    expect(screen.getAllByText("Critical").length).toBeGreaterThanOrEqual(2); // badge + select option
    expect(screen.getAllByText("Healthy").length).toBeGreaterThanOrEqual(2);
    expect(screen.getAllByText("Not crawled").length).toBeGreaterThan(0);

    // Null columns read as "—", never as fake zeros.
    expect(screen.getAllByText("99.8%").length).toBeGreaterThan(0);
    expect(screen.getAllByText("1,200").length).toBeGreaterThan(0);
    // The critical-issue count is danger-toned (the tiles also hold a "3").
    const threes = screen.getAllByText("3");
    expect(threes.some((el) => el.classList.contains("text-bad"))).toBe(true);
  });

  it("passes the client and health filters to the portfolio fetch", async () => {
    const user = userEvent.setup();
    render(<AgencyPortfolioPage />);

    await screen.findByTestId("tile-sites");

    await user.selectOptions(screen.getByLabelText("Filter by client"), "11");
    await user.selectOptions(screen.getByLabelText("Filter by health"), "critical");

    // The refetch resolves with the same fixture; give it a beat to land.
    await screen.findAllByText("Acme Store");

    const portfolioCalls = apiMocks.apiGet.mock.calls.map(([p]) => String(p)).filter((p) => p.includes("/agency/portfolio"));
    expect(portfolioCalls.some((p) => p.includes("client_id=11"))).toBe(true);
    expect(portfolioCalls.some((p) => p.includes("health=critical"))).toBe(true);
    // "all" never sends a param.
    expect(portfolioCalls.some((p) => p.includes("health=all") || p.includes("client_id=all"))).toBe(false);
  });

  it("adds a client with a POST carrying the form values", async () => {
    const user = userEvent.setup();
    render(<AgencyPortfolioPage />);

    await screen.findAllByText("Acme Co");

    await user.type(screen.getByLabelText("Client name"), "Globex");
    await user.type(screen.getByLabelText("Contact email (optional)"), "hi@globex.example");
    await user.click(screen.getByRole("button", { name: /Add client/ }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/agency/clients", {
      name: "Globex",
      contact_email: "hi@globex.example",
    });
    expect(await screen.findByText('Client "Globex" added.')).toBeInTheDocument();
  });

  it("assigns sites with the exact sync payload", async () => {
    const user = userEvent.setup();
    render(<AgencyPortfolioPage />);

    await screen.findAllByText("Acme Co");

    await user.click(screen.getByRole("button", { name: /Assign sites/ }));

    // The panel lists the org's sites from the dashboard context, prefilled
    // with the client's CURRENT assignment (both Acme Co sites start checked).
    const panel = await screen.findByTestId("assign-panel");
    expect(within(panel).getByText("Acme Store")).toBeInTheDocument();
    expect(within(panel).getByLabelText("Assign Acme Store")).toBeChecked();
    expect(within(panel).getByLabelText("Assign Beta Blog")).toBeChecked();

    // Sync semantics: unchecking Beta Blog unassigns it — the checked set is
    // the client's EXACT site list.
    await user.click(within(panel).getByLabelText("Assign Beta Blog"));
    await user.click(within(panel).getByRole("button", { name: "Save assignment" }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/agency/clients/11/sites", { site_ids: [1] });
    expect(await screen.findByText("Acme Co now has 1 assigned site.")).toBeInTheDocument();
  });

  it("deletes a client only after the unassign-not-delete confirmation", async () => {
    const user = userEvent.setup();
    render(<AgencyPortfolioPage />);

    await screen.findAllByText("Acme Co");

    await user.click(screen.getByRole("button", { name: "Delete" }));

    expect(globalThis.confirm).toHaveBeenCalledWith(
      'Delete client "Acme Co"? Sites will be unassigned, not deleted.',
    );
    expect(apiMocks.apiDelete).toHaveBeenCalledWith("/agency/clients/11");
    expect(await screen.findByText('Client "Acme Co" deleted — its sites are now unassigned.')).toBeInTheDocument();
  });

  it("answers a 403 on a client write with a graceful message", async () => {
    apiMocks.apiPost.mockRejectedValueOnce({ status: 403, message: "Forbidden" });
    const user = userEvent.setup();
    render(<AgencyPortfolioPage />);

    await screen.findAllByText("Acme Co");

    await user.type(screen.getByLabelText("Client name"), "Nope Inc");
    await user.click(screen.getByRole("button", { name: /Add client/ }));

    expect(await screen.findByText(/You don't have permission to manage agency clients/)).toBeInTheDocument();
    // The workflow stays usable — no crash, form intact.
    expect(screen.getByLabelText("Client name")).toHaveValue("Nope Inc");
  });
});
