import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Admin observability page (spec Phase 2.3).
 *
 * The failure this page must not have is looking healthy when it is not:
 * an exporter with no endpoint reading the same as one that is exporting, a
 * stalled queue reading as fine because the depth happens to be low, or a
 * sub-cent AI cost rounding to $0.00 and hiding spend entirely.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

import ObservabilityPage from "@/app/admin/observability/page";

const base = {
  window_hours: 24,
  requests: {
    has_data: true,
    totals: { requests: 1200, server_errors: 3, client_errors: 40, error_rate: 0.25 },
    routes: [
      { route: "sites/{site}/insights", method: "GET", requests: 400, avg_ms: 1450, max_ms: 5200, over_1s: 120, over_3s: 8, server_errors: 3, client_errors: 0, error_rate: 0.75 },
    ],
  },
  series: [],
  ai: {
    has_data: true,
    totals: { calls: 90, failures: 2, tokens_in: 120000, tokens_out: 30000, cost_usd: 0.004321 },
    breakdown: [
      { kind: "insight", provider: "openai", model: "gpt-4o-mini", calls: 80, failures: 2, failure_rate: 2.5, avg_ms: 980, max_ms: 4100, tokens_in: 100000, tokens_out: 25000, cost_usd: 0.004 },
    ],
  },
  queue: {
    driver: "database",
    supported: true,
    pending: 2,
    queues: [],
    oldest_pending_age_seconds: 2700,
    failed: { total: 1, last_24h: 1, recent: [{ id: 1, uuid: "u", queue: "default", job: "App\\Jobs\\SyncDataSource", failed_at: "2026-07-27 10:00:00", error: "RuntimeException: token expired" }] },
    worker: { last_seen_at: null, age_seconds: null, stale: true, stale_after_seconds: 300 },
    status: { level: "critical", detail: "Jobs are waiting and no worker has reported in." },
  },
  exporters: {
    errors: { driver: "sentry", configured: false, environment: "production", release: null, traces_sample_rate: 0 },
    tracing: { driver: "opentelemetry", enabled: true, exporting: false, service: "insightistic-api", sample_rate: 0.05, endpoint_host: null },
    metrics: { driver: "database", enabled: true, retention_days: 30, buckets: { requests: 120, ai: 20 } },
  },
};

describe("Admin observability page", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    apiMocks.apiGet.mockResolvedValue(base);
  });

  it("requests a 24 hour window by default", async () => {
    render(<ObservabilityPage />);

    await waitFor(() => expect(apiMocks.apiGet).toHaveBeenCalledWith("/admin/observability?hours=24"));
  });

  it("changes the window server-side rather than filtering locally", async () => {
    render(<ObservabilityPage />);
    await waitFor(() => expect(screen.getByRole("heading", { name: "Requests" })).toBeInTheDocument());

    await userEvent.click(screen.getByRole("button", { name: "7d" }));

    await waitFor(() => expect(apiMocks.apiGet).toHaveBeenCalledWith("/admin/observability?hours=168"));
  });

  it("surfaces a stalled queue with the reason, not just a depth", async () => {
    render(<ObservabilityPage />);

    await waitFor(() => expect(screen.getByText("critical")).toBeInTheDocument());
    // Two pending jobs is a small number; 45 minutes of waiting is the finding.
    expect(screen.getByText(/no worker has reported in/)).toBeInTheDocument();
    expect(screen.getByText("45m")).toBeInTheDocument();
    expect(screen.getByText("Stale")).toBeInTheDocument();
  });

  it("distinguishes an unconfigured exporter from one that is exporting", async () => {
    render(<ObservabilityPage />);

    await waitFor(() => expect(screen.getByText("Exporters")).toBeInTheDocument());

    // Sentry has no DSN; tracing is enabled but has nowhere to send. Both are
    // "not working", and saying so differently is the point.
    expect(screen.getByText("off")).toBeInTheDocument();
    expect(screen.getByText("no endpoint")).toBeInTheDocument();
  });

  it("shows sub-cent AI cost at full precision instead of rounding it to zero", async () => {
    render(<ObservabilityPage />);

    await waitFor(() => expect(screen.getByText("AI spend")).toBeInTheDocument());

    // $0.00 would read as "AI is free", which is exactly the wrong conclusion.
    expect(screen.getByText("$0.004321")).toBeInTheDocument();
    expect(screen.queryByText("$0.00")).not.toBeInTheDocument();
  });

  it("renders latency in seconds once it passes a second", async () => {
    render(<ObservabilityPage />);

    await waitFor(() => expect(screen.getByText(/sites\/\{site\}\/insights/)).toBeInTheDocument());
    expect(screen.getByText("1.45s")).toBeInTheDocument();
    expect(screen.getByText("5.20s")).toBeInTheDocument();
  });

  it("says so plainly when a window has no telemetry rather than showing zeroes", async () => {
    apiMocks.apiGet.mockResolvedValue({
      ...base,
      requests: { has_data: false, totals: {}, routes: [] },
      ai: { has_data: false, totals: {}, breakdown: [] },
    });
    render(<ObservabilityPage />);

    await waitFor(() =>
      expect(screen.getByText(/No request telemetry recorded in this window/)).toBeInTheDocument(),
    );
    expect(screen.getByText(/No AI calls recorded in this window/)).toBeInTheDocument();
  });

  it("surfaces a failed load instead of an empty dashboard", async () => {
    apiMocks.apiGet.mockRejectedValue({ status: 500, message: "Observability unavailable" });
    render(<ObservabilityPage />);

    await waitFor(() => expect(screen.getByText("Observability unavailable")).toBeInTheDocument());
  });
});
