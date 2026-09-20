import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Phase 6 workstream: the IndexNow page (roadmap doc 11 §URL submission UI).
 * Mocking mirrors tests/traffic-page.test.tsx — the API layer is stubbed, the
 * real page renders. Pins: key status + confirmation badge, manual submit
 * posts the parsed URL list and shows the counted result (skips included),
 * failed submission rows show their error, and regenerating confirms first
 * then refreshes the status card.
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

import IndexNowPage from "@/app/dashboard/indexnow/page";

function statusPayload(overrides: { status?: Record<string, any>; submissions?: any[] } = {}) {
  // Low-entropy fake: gitleaks' generic-api-key rule flags hex fixtures.
  return {
    status: {
      key: "sample-indexnow-key",
      key_location: "https://example.com/sample-indexnow-key.txt",
      key_file_confirmed: true,
      last_submitted_at: "2026-09-19T10:00:00.000000Z",
      counts: { accepted: 3, failed: 1, pending: 2 },
      ...overrides.status,
    },
    submissions: overrides.submissions ?? [
      {
        id: 2,
        url: "https://example.com/accepted-page",
        source: "manual",
        status: "accepted",
        http_status: 200,
        error: null,
        created_at: "2026-09-19T09:30:00.000000Z",
      },
      {
        id: 1,
        url: "https://example.com/deep/failed-page",
        source: "plugin",
        status: "failed",
        http_status: 403,
        error: "Key file not reachable (HTTP 403)",
        created_at: "2026-09-19T09:00:00.000000Z",
      },
    ],
  };
}

function respond(payload: any) {
  apiMocks.apiGet.mockImplementation((path: string) =>
    String(path).includes("/indexnow") ? Promise.resolve(payload) : Promise.resolve({}),
  );
}

// ResponsiveTable renders desktop rows and mobile cards from the same data,
// so row text appears twice in the DOM — assert against all matches.
const anyRowText = (text: string) => screen.getAllByText(text).length;

describe("IndexNow page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders the status card with the confirmed key badge and the submission counts", async () => {
    respond(statusPayload());
    render(<IndexNowPage />);

    expect(await screen.findByText("Key file active")).toBeInTheDocument();
    expect(screen.getByText("sample-indexnow-key")).toBeInTheDocument();
    expect(screen.getByText("https://example.com/sample-indexnow-key.txt")).toBeInTheDocument();
    expect(screen.getByText("Accepted")).toBeInTheDocument();
    expect(screen.getByText("3")).toBeInTheDocument();
  });

  it("shows the waiting badge while the plugin hasn't published the key file yet", async () => {
    respond(statusPayload({ status: { key_file_confirmed: false } }));
    render(<IndexNowPage />);

    expect(await screen.findByText("Waiting for plugin to publish sample-indexnow-key.txt")).toBeInTheDocument();
    expect(screen.queryByText("Key file active")).not.toBeInTheDocument();
  });

  it("renders failed submission rows with their error, next to accepted ones", async () => {
    respond(statusPayload());
    render(<IndexNowPage />);

    // "Key & status" only renders once the fetch has resolved.
    await screen.findByText("Key & status");
    expect(anyRowText("Key file not reachable (HTTP 403)")).toBeGreaterThan(0);
    expect(anyRowText("https://example.com/accepted-page")).toBeGreaterThan(0);
    expect(anyRowText("https://example.com/deep/failed-page")).toBeGreaterThan(0);
    expect(anyRowText("accepted")).toBeGreaterThan(0);
    expect(anyRowText("failed")).toBeGreaterThan(0);
    expect(anyRowText("plugin")).toBeGreaterThan(0);
  });

  it("submits the parsed URL list and shows the counted result including skips", async () => {
    respond(statusPayload());
    apiMocks.apiPost.mockResolvedValue({
      batch_id: "b1",
      submitted: 3,
      accepted: 2,
      failed: 0,
      skipped_duplicate: 0,
      skipped_invalid_host: 1,
    });
    const user = userEvent.setup();
    render(<IndexNowPage />);

    await screen.findByText("Key file active");

    const submitButton = screen.getByRole("button", { name: "Submit URLs" });
    expect(submitButton).toBeDisabled(); // nothing to submit yet

    await user.type(
      screen.getByLabelText("URLs to submit"),
      "https://example.com/a\nhttps://example.com/b\nnot a url",
    );
    // The invalid line is flagged inline; submit stays enabled for the valid ones.
    expect(await screen.findByText(/not a valid http\(s\) URL/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Submit URLs" })).toBeEnabled();

    await user.click(screen.getByRole("button", { name: "Submit URLs" }));

    expect(await screen.findByText(/Submitted 3 URLs — 2 accepted, 0 failed, 1 skipped — different host\./)).toBeInTheDocument();
    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/indexnow/submit", {
      urls: ["https://example.com/a", "https://example.com/b"],
    });
    // The status card refreshes after a submission.
    const calls = apiMocks.apiGet.mock.calls.map(([p]) => String(p)).filter((p) => p.includes("/indexnow"));
    expect(calls.length).toBeGreaterThanOrEqual(2);
  });

  it("confirms before regenerating the key, then refetches the status", async () => {
    // The status payload flips to "unconfirmed" once the regeneration lands,
    // so the post-POST refetch shows the waiting badge.
    let confirmed = true;
    apiMocks.apiGet.mockImplementation((path: string) =>
      String(path).includes("/indexnow")
        ? Promise.resolve(statusPayload({ status: { key_file_confirmed: confirmed } }))
        : Promise.resolve({}),
    );
    apiMocks.apiPost.mockResolvedValue({ status: { key: "sample-indexnow-key", key_file_confirmed: false } });
    const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(true);
    const user = userEvent.setup();
    render(<IndexNowPage />);

    await screen.findByText("Key file active");

    confirmed = false;
    await user.click(screen.getByRole("button", { name: "Regenerate key" }));

    expect(confirmSpy).toHaveBeenCalledWith(expect.stringContaining("Old key stops working immediately"));
    expect(apiMocks.apiPost).toHaveBeenCalledWith("/sites/1/indexnow/regenerate-key");
    // Refetch reflects the new unconfirmed key.
    expect(await screen.findByText(/Waiting for plugin to publish sample-indexnow-key\.txt/)).toBeInTheDocument();
  });

  it("does not regenerate when the confirm dialog is dismissed", async () => {
    respond(statusPayload());
    vi.spyOn(window, "confirm").mockReturnValue(false);
    const user = userEvent.setup();
    render(<IndexNowPage />);

    await screen.findByText("Key file active");
    await user.click(screen.getByRole("button", { name: "Regenerate key" }));

    expect(apiMocks.apiPost).not.toHaveBeenCalled();
  });

  it("guides toward the plugin connection when the status fetch fails", async () => {
    apiMocks.apiGet.mockImplementation(() => Promise.reject(new Error("Not found.")));
    render(<IndexNowPage />);

    expect(await screen.findByText("IndexNow isn't available for this site")).toBeInTheDocument();
    expect(screen.getByText("Not found.")).toBeInTheDocument();
    expect(screen.getByText(/delivered through the Insightistic WordPress plugin/)).toBeInTheDocument();
  });
});
