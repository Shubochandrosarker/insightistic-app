import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 12 workstream UI (Uptime, roadmap doc 16). Mocking mirrors
 * tests/traffic-page.test.tsx — the API layer is stubbed, the real page
 * renders. Pins: the setup card when no monitor exists, the monitor card's
 * 30-day availability + SSL tone ladder, the suspected-vs-confirmed-vs-
 * recovered incident badges (doc 16: one failed probe never alerts —
 * confirmed_at null means "waiting for the second probe"), the check-now
 * probe result, and the create-monitor POST carrying the chosen interval.
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
    sites: [{ id: 1, name: "Test Shop", domain: "testshop.com" }],
  }),
}));

import UptimePage from "@/app/dashboard/uptime/page";

const hoursAgo = (n: number) => new Date(Date.now() - n * 3_600_000).toISOString();

function uptimePayload(overrides: Record<string, any> = {}) {
  return {
    has_monitor: true,
    monitor: {
      id: 3,
      url: "https://testshop.com",
      interval_minutes: 5,
      last_checked_at: hoursAgo(1),
      last_check: { status: "up", status_code: 200, response_ms: 180, error: null, checked_at: hoursAgo(1) },
    },
    incidents: [],
    ssl: null,
    availability_pct: 99,
    ...overrides,
  };
}

function mockUptime(payload: any) {
  apiMocks.apiGet.mockImplementation((path: string) =>
    String(path).includes("/uptime") ? Promise.resolve(payload) : Promise.resolve({}),
  );
}

const noMonitor = { has_monitor: false, monitor: null, incidents: [], ssl: null, availability_pct: null };

describe("Uptime page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("shows the setup card when the site has no monitor yet", async () => {
    mockUptime(noMonitor);
    render(<UptimePage />);

    expect(await screen.findByText("Set up uptime monitoring")).toBeInTheDocument();
    expect(screen.getByPlaceholderText("https://domain")).toBeInTheDocument();
    // No monitor, nothing to check or table to show.
    expect(screen.queryByRole("button", { name: "Check now" })).not.toBeInTheDocument();
  });

  it("renders the monitor card with 30-day availability, target and interval", async () => {
    mockUptime(uptimePayload());
    render(<UptimePage />);

    expect(await screen.findByText("99%")).toBeInTheDocument();
    expect(screen.getByText("https://testshop.com")).toBeInTheDocument();
    expect(screen.getByText(/every 5 min/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Check now" })).toBeInTheDocument();
  });

  it("tones the SSL line danger inside the critical window", async () => {
    mockUptime(uptimePayload({ ssl: { expires_at: "2026-10-01", days_left: 3, tone: "critical" } }));
    render(<UptimePage />);

    const line = await screen.findByText(/3 days left/);
    expect(line).toHaveClass("text-bad");
    expect(line).toHaveTextContent("2026-10-01");
  });

  it("says the SSL certificate is not checked yet when no scan has run", async () => {
    mockUptime(uptimePayload());
    render(<UptimePage />);

    expect(await screen.findByText("Not checked yet")).toBeInTheDocument();
  });

  it("separates suspected, confirmed-ongoing and recovered incidents", async () => {
    mockUptime(
      uptimePayload({
        incidents: [
          {
            id: 1,
            started_at: hoursAgo(1),
            confirmed_at: null, // first failed probe only — suspected, doc 16
            recovered_at: null,
            cause: "Connection timeout",
            status_code: null,
          },
          {
            id: 2,
            started_at: hoursAgo(30),
            confirmed_at: hoursAgo(29), // second consecutive failure — confirmed
            recovered_at: null,
            cause: "HTTP 500 from origin",
            status_code: 500,
          },
          {
            id: 3,
            started_at: hoursAgo(2),
            confirmed_at: hoursAgo(1.9),
            recovered_at: hoursAgo(1.75), // 15 minutes of downtime
            cause: "HTTP 503",
            status_code: 503,
          },
        ],
      }),
    );
    render(<UptimePage />);

    expect((await screen.findAllByText("Connection timeout")).length).toBeGreaterThan(0);
    // The unconfirmed incident carries the explicit "Suspected" state…
    expect(screen.getAllByText("Suspected").length).toBeGreaterThan(0);
    expect(
      screen.getAllByTitle("Waiting for the second probe — one failed probe never alerts.").length,
    ).toBeGreaterThan(0);
    // …and both still-open incidents read as Ongoing (desktop + mobile layouts).
    expect(screen.getAllByText("Ongoing").length).toBe(4);
    // The recovered incident's duration is computed from its milestones.
    expect(screen.getAllByText("15m").length).toBeGreaterThan(0);
  });

  it("renders an up probe result with status code and response time after Check now", async () => {
    mockUptime(uptimePayload());
    apiMocks.apiPost.mockResolvedValue({
      result: { status: "up", status_code: 200, response_ms: 231, error: null, checked_at: new Date().toISOString() },
      incident: null,
    });
    const user = userEvent.setup();
    render(<UptimePage />);

    await user.click(await screen.findByRole("button", { name: "Check now" }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/uptime/check", {});
    expect(await screen.findByText("Up")).toBeInTheDocument();
    expect(screen.getByText("231")).toBeInTheDocument();
    expect(screen.getByText("200")).toBeInTheDocument();
  });

  it("renders a down probe result with the probe error and open-incident note", async () => {
    mockUptime(uptimePayload());
    apiMocks.apiPost.mockResolvedValue({
      result: { status: "down", status_code: null, response_ms: null, error: "Connection timed out after 10s" },
      incident: { id: 9, cause: "Connection timeout" },
    });
    const user = userEvent.setup();
    render(<UptimePage />);

    await user.click(await screen.findByRole("button", { name: "Check now" }));

    expect(await screen.findByText("Down")).toBeInTheDocument();
    expect(screen.getByText("Connection timed out after 10s")).toBeInTheDocument();
    expect(screen.getByText(/an incident is open/i)).toBeInTheDocument();
  });

  it("creates the monitor with the chosen interval and reloads", async () => {
    mockUptime(noMonitor);
    apiMocks.apiPost.mockResolvedValue({ has_monitor: true, monitor: {} });
    const user = userEvent.setup();
    render(<UptimePage />);

    await user.type(await screen.findByLabelText("Target URL"), "https://custom.example");
    await user.selectOptions(screen.getByLabelText("Check interval"), "15");
    await user.click(screen.getByRole("button", { name: "Create monitor" }));

    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/uptime/monitors", {
      url: "https://custom.example",
      interval_minutes: 15,
    });
    await waitFor(() =>
      expect(apiMocks.apiGet.mock.calls.filter(([p]) => String(p).includes("/uptime")).length).toBeGreaterThanOrEqual(2),
    );
  });

  it("shows a graceful 403 message when the viewer may not configure monitoring", async () => {
    mockUptime(noMonitor);
    apiMocks.apiPost.mockRejectedValue({ status: 403, message: "Forbidden" });
    const user = userEvent.setup();
    render(<UptimePage />);

    await user.click(await screen.findByRole("button", { name: "Create monitor" }));

    expect(await screen.findByText(/don't have permission to configure uptime monitoring/i)).toBeInTheDocument();
  });
});
