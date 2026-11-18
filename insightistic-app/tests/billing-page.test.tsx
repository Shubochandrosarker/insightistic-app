import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);
vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ sites: [] }),
}));

import BillingPage from "@/app/dashboard/billing/page";

const subscription = {
  organization: { status: "trialing" },
  plan: { slug: "free", name: "Free trial", price_monthly: 0, price_yearly: 0, site_limit: 1 },
  usage: {
    ai_insights: { used: 0, limit: 10 },
    jolly_messages: { used: 0, limit: 10 },
    standard_ai_actions: { used: 0, limit: 10 },
    reports: { used: 0, limit: 0 },
  },
  credits: { monthly_included: { granted: 1000, remaining: 1000 } },
  subscription: {},
};

const catalog = {
  plans: [
    { slug: "starter", name: "Starter", price_monthly: 2900, price_yearly: 29000, site_limit: 1 },
    { slug: "growth", name: "Growth", price_monthly: 6900, price_yearly: 69000, site_limit: 3 },
    { slug: "agency", name: "Agency", price_monthly: 14900, price_yearly: 149000, site_limit: 10 },
    { slug: "agency_pro", name: "Agency Pro", price_monthly: 29900, price_yearly: 299000, site_limit: 30 },
  ],
  appsumo: {
    plans: [1, 2, 3, 4, 5].map((tier) => ({
      slug: `appsumo_tier_${tier}`,
      name: `AppSumo Tier ${tier}`,
      price: [49, 79, 149, 269, 499][tier - 1],
      sites: [1, 3, 10, 25, 50][tier - 1],
      monthly_ai_credits: [5000, 10000, 30000, 75000, 250000][tier - 1],
    })),
  },
};

beforeEach(() => {
  vi.clearAllMocks();
  apiMocks.apiGet.mockImplementation((path: string) =>
    path === "/billing/subscription" ? Promise.resolve(subscription) : Promise.resolve(catalog),
  );
  apiMocks.apiPost.mockResolvedValue({ url: "https://checkout.stripe.com/c/pay/test" });
});

describe("Billing page", () => {
  it("shows only Stripe subscriptions and AppSumo lifetime tiers", async () => {
    render(<BillingPage />);

    expect(await screen.findByText("Stripe subscription plans")).toBeInTheDocument();
    expect(screen.getByText("AppSumo lifetime tiers")).toBeInTheDocument();
    expect(screen.getByText("AppSumo Tier 5")).toBeInTheDocument();
  });

  it("starts recurring checkout through the Stripe endpoint", async () => {
    render(<BillingPage />);
    const checkoutButton = await screen.findByRole("button", { name: "Continue to Stripe checkout" });

    await userEvent.selectOptions(screen.getByLabelText("Billing interval"), "yearly");
    await userEvent.click(checkoutButton);

    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/checkout", {
      plan: "growth",
      interval: "yearly",
    }));
  });
});
