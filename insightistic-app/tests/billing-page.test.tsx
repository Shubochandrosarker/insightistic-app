import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import BillingPage from "@/app/dashboard/billing/page";

const { apiPost, useApi } = vi.hoisted(() => ({ apiPost: vi.fn(), useApi: vi.fn() }));
vi.mock("@/lib/api", () => ({ apiPost }));
vi.mock("@/lib/useApi", () => ({ useApi }));

describe("Current-plan billing", () => {
  beforeEach(() => vi.resetAllMocks());

  it("shows AppSumo Tier 5 and usage without recurring prices or checkout", () => {
    useApi.mockReturnValue({
      data: {
        plan: { name: "AppSumo Tier 5", price_monthly: 49900, billing_model: "lifetime" },
        lifetime_grant: { active: true, name: "AppSumo Tier 5", provider: "appsumo" },
        stripe_portal_available: true,
        usage: { connected_sites: { used: 1, limit: 50 }, jolly_messages: { used: 1, limit: 300 } },
      },
      loading: false,
    });
    render(<BillingPage />);
    expect(screen.getByText("AppSumo Tier 5")).toBeVisible();
    expect(screen.getByText("Lifetime")).toBeVisible();
    expect(screen.getByText("1 / 50")).toBeVisible();
    expect(screen.getByText("1 / 300")).toBeVisible();
    expect(screen.queryByText(/499/)).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /checkout|stripe|upgrade/i })).not.toBeInTheDocument();
  });

  it("shows the Stripe subscription and owner customer portal for the active plan", async () => {
    useApi.mockReturnValue({
      data: {
        plan: { name: "Growth" },
        stripe_subscription: true,
        stripe_portal_available: true,
        stripe_price: { amount_minor: 6900, currency: "usd", billing_interval: "month" },
        subscription: { status: "active", current_period_end: "2026-12-01T15:00:00Z" },
      },
      loading: false,
    });
    apiPost.mockRejectedValueOnce(new Error("Portal unavailable"));
    render(<BillingPage />);
    expect(screen.getByText("Growth")).toBeVisible();
    expect(screen.getByText(/\$69\.00/)).toBeVisible();
    expect(screen.getByText(/Current period ends/)).toBeVisible();
    fireEvent.click(screen.getByRole("button", { name: "Open Stripe customer portal" }));
    await waitFor(() => expect(apiPost).toHaveBeenCalledWith("/billing/portal"));
    expect(await screen.findByRole("alert")).toHaveTextContent("Portal unavailable");
  });
});
