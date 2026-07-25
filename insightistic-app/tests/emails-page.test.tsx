import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen } from "@testing-library/react";

/**
 * The Emails page carries the platform's "no fabricated analytics" rule at its
 * sharpest: a metric the provider did not report must render as an em dash,
 * never as 0 or 0%. These tests exist because that distinction is exactly the
 * kind of thing that silently regresses into confident-looking zeroes.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
  apiDelete: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ siteId: 1, sites: [{ id: 1, name: "Test Shop" }] }),
}));

import EmailsPage from "@/app/dashboard/emails/page";

const disconnected = {
  connection: {
    connected: false,
    provider: null,
    account: null,
    available_providers: [
      { key: "mailchimp", label: "Mailchimp" },
      { key: "klaviyo", label: "Klaviyo" },
    ],
    connector_providers: ["fluentcrm", "automatewoo", "woocommerce"],
  },
  overview: {
    window_days: 90,
    campaigns: 0,
    providers: [],
    totals: { sent: null, delivered: null, bounced: null, opened: null, clicked: null, unsubscribed: null, conversions: null, revenue: null },
    rates: { delivery: null, open: null, click: null, bounce: null },
    currency: null,
  },
};

function respond(overview: any, campaigns: any[] = []) {
  apiMocks.apiGet.mockImplementation((path: string) =>
    path.includes("/emails/overview")
      ? Promise.resolve(overview)
      : Promise.resolve({ campaigns }),
  );
}

describe("Emails page", () => {
  beforeEach(() => vi.clearAllMocks());

  it("renders unreported metrics as an em dash, never as zero", async () => {
    respond(disconnected);
    render(<EmailsPage />);

    // All four KPI tiles must show "—" rather than 0 / 0.0%.
    const dashes = await screen.findAllByText("—");
    expect(dashes.length).toBeGreaterThanOrEqual(4);
    expect(screen.queryByText("0%")).not.toBeInTheDocument();
    expect(screen.queryByText("0.0%")).not.toBeInTheDocument();
  });

  it("offers both connection routes when nothing is connected", async () => {
    respond(disconnected);
    render(<EmailsPage />);

    expect(await screen.findByText("Connect an email platform")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Mailchimp" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Klaviyo" })).toBeInTheDocument();

    // The plugin-fed platforms are named so users know they need no API key.
    expect(screen.getByText(/FluentCRM, AutomateWoo and WooCommerce mail/)).toBeInTheDocument();
  });

  it("shows real figures and rates once campaigns exist", async () => {
    respond(
      {
        connection: { ...disconnected.connection, connected: true, provider: "mailchimp", account: "Acme Store" },
        overview: {
          window_days: 90,
          campaigns: 1,
          providers: ["mailchimp"],
          totals: { sent: 1000, delivered: 990, bounced: 10, opened: 400, clicked: 90, unsubscribed: 4, conversions: 12, revenue: 845.5 },
          rates: { delivery: 99, open: 40.4, click: 9.09, bounce: 1 },
          currency: "USD",
        },
      },
      [
        {
          id: 1, provider: "mailchimp", name: "July newsletter", type: "campaign", status: "sent",
          sent_at: new Date().toISOString(), sent: 1000, delivered: 990, opened: 400, clicked: 90,
          revenue: 845.5, currency: "USD",
          rates: { delivery: 99, open: 40.4, click: 9.09, bounce: 1, unsubscribe: 0.4 },
        },
      ],
    );

    render(<EmailsPage />);

    // Node's locale in CI may not add a thousands separator, so match either form.
    expect(await screen.findAllByText(/^1[,.\s]?000$/)).not.toHaveLength(0);
    expect(screen.getAllByText("40.4%").length).toBeGreaterThan(0);
    expect(screen.getByText("July newsletter")).toBeInTheDocument();
    expect(screen.getByText("Connected as Acme Store")).toBeInTheDocument();
  });

  it("keeps a reported zero distinct from a missing metric", async () => {
    respond(
      {
        connection: { ...disconnected.connection, connected: true, provider: "mailchimp", account: "Acme" },
        overview: {
          window_days: 90,
          campaigns: 1,
          providers: ["mailchimp"],
          // bounced is a genuine 0; unsubscribed was never reported.
          totals: { sent: 500, delivered: 500, bounced: 0, opened: null, clicked: null, unsubscribed: null, conversions: null, revenue: null },
          rates: { delivery: 100, open: null, click: null, bounce: 0 },
          currency: null,
        },
      },
      [],
    );

    render(<EmailsPage />);

    // A real zero renders as a zero...
    expect(await screen.findByText("500")).toBeInTheDocument();
    // ...while unreported open/click rates stay em dashes.
    expect(screen.getAllByText("—").length).toBeGreaterThanOrEqual(2);
  });

  it("surfaces an API failure with a retry instead of a blank page", async () => {
    apiMocks.apiGet.mockRejectedValue({ status: 500, message: "Server exploded" });
    render(<EmailsPage />);

    expect(await screen.findByText("Server exploded")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Try again" })).toBeInTheDocument();
  });
});
