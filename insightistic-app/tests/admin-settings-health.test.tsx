import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Admin settings + system-health pages (2026-10-13 incident pins).
 *
 * 1. Settings used to fetch /admin/system-health (copy-paste) and there was
 *    no GET /admin/settings endpoint at all — it must call the real one now.
 * 2. Both pages rendered `loading || !data` as an infinite skeleton: any
 *    401/403/500 left the founder staring at "Loading" forever. A failed
 *    admin fetch must show an explicit error state with a retry instead.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

import AdminSettings from "@/app/admin/settings/page";
import AdminSystemHealth from "@/app/admin/system-health/page";

const settings = {
  release: { sha: "abc1234", built_at: "2026-10-13 10:00" },
  app: { env: "production", url: "https://app.insightistic.com", debug: false },
  database: { status: "ok", driver: "pgsql" },
  queue: { pending: 0, failed: 0, connection: "database" },
  cache: { driver: "database", ok: true },
  storage: { writable: true, reports_disk: "public" },
  scheduler: { status: "configured", detail: "nightly snapshots 02:00" },
  mail: { mailer: "smtp", configured: true },
  stripe: { configured: false, mode: "test" },
  ai: { provider: "plan_aware", configured: true },
  counts: { organizations: 7, users: 13, sites: 21, plans: 5, super_admins: 1 },
};

const health = {
  api: { status: "ok", url: "https://api.insightistic.com" },
  app: { status: "ok", env: "production", url: "https://app.insightistic.com", debug: false },
  release: { sha: "abc1234", built_at: "2026-10-13 10:00" },
  database: { status: "ok", driver: "pgsql" },
  cache: { driver: "database", ok: true },
  queue: { pending: 1, failed: 0, connection: "database" },
  scheduler: { status: "configured", detail: "nightly snapshots 02:00" },
  storage: { writable: true, reports_disk: "public" },
  mail: { mailer: "smtp", configured: true },
  stripe: { configured: true, mode: "test", api: "ok", webhook_signing_configured: true },
  ai: { provider: "plan_aware", configured: true, reachable: true },
  recent_failed_jobs: [],
};

describe("Admin settings page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    apiMocks.apiGet.mockResolvedValue(settings);
  });

  it("fetches the dedicated /admin/settings endpoint (not system-health)", async () => {
    render(<AdminSettings />);

    await waitFor(() => expect(apiMocks.apiGet).toHaveBeenCalledWith("/admin/settings"));
    expect(apiMocks.apiGet).not.toHaveBeenCalledWith("/admin/system-health");
  });

  it("renders platform facts: release, counts and app env mode", async () => {
    render(<AdminSettings />);

    await waitFor(() => expect(screen.getByText("abc1234")).toBeInTheDocument());
    expect(screen.getByText("7")).toBeInTheDocument();
    expect(screen.getByText("13")).toBeInTheDocument();
    // App env badge; the Paddle integration row is gone (Stripe-only surface).
    expect(screen.getByText("production")).toBeInTheDocument();
    expect(screen.getAllByText(/no secrets/i).length).toBeGreaterThanOrEqual(1);
  });

  it("shows an explicit error state instead of an infinite skeleton on API failure", async () => {
    apiMocks.apiGet.mockRejectedValue({ status: 403, message: "Super admin access required." });
    render(<AdminSettings />);

    await waitFor(() => expect(screen.getByText("Super admin access required.")).toBeInTheDocument());
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });

  it("retries on demand after a failure", async () => {
    apiMocks.apiGet.mockRejectedValueOnce({ status: 500, message: "Server error" });
    render(<AdminSettings />);

    await waitFor(() => expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument());
    await userEvent.click(screen.getByRole("button", { name: "Retry" }));

    await waitFor(() => expect(screen.getByText("abc1234")).toBeInTheDocument());
  });
});

describe("Admin system health page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    apiMocks.apiGet.mockResolvedValue(health);
  });

  it("renders the real health payload sections", async () => {
    render(<AdminSystemHealth />);

    await waitFor(() => expect(screen.getByText("API")).toBeInTheDocument());
    expect(screen.getByText("Database")).toBeInTheDocument();
    expect(screen.getByText("Stripe")).toBeInTheDocument();
    expect(screen.queryByText("Paddle")).not.toBeInTheDocument();
    expect(screen.getByText("Cache")).toBeInTheDocument();
    expect(screen.getByText("Recent failed jobs")).toBeInTheDocument();
    expect(screen.getByText("No failed jobs 🎉")).toBeInTheDocument();
  });

  it("shows an explicit error state instead of an infinite skeleton on API failure", async () => {
    apiMocks.apiGet.mockRejectedValue({ status: 500, message: "Server error" });
    render(<AdminSystemHealth />);

    await waitFor(() => expect(screen.getByText("Server error")).toBeInTheDocument());
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
  });
});
