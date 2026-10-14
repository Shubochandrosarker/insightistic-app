import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, act } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Billing checkout, provider-aware (roadmap 22/22A):
 *  - the Paddle path asks /billing/checkout-config and opens a Paddle.js
 *    overlay with the API-issued price/token, then polls /billing/subscription
 *    until the webhook projects the purchase (never trusting the client
 *    callback alone);
 *  - Stripe-era organizations (501 paddle_not_enabled) keep the legacy
 *    /billing/checkout redirect.
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

const paddleMocks = vi.hoisted(() => ({
  initializePaddle: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);
vi.mock("@paddle/paddle-js", () => paddleMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ sites: [{ id: 1, name: "Test Shop" }] }),
}));

import BillingPage from "@/app/dashboard/billing/page";
import { resetPaddleForTests } from "@/lib/paddle";

const freeOrg = {
  organization: { status: "trialing" },
  plan: { name: "Free trial" },
  usage: { ai_insights: { used: 0, limit: 15 }, reports: { used: 0, limit: 4 } },
  subscription: {},
};

const paddleOrg = {
  organization: { status: "active" },
  plan: { name: "Growth" },
  usage: { ai_insights: { used: 2, limit: 75 }, reports: { used: 1, limit: 10 } },
  subscription: { billing_provider: "paddle", provider_subscription_id: "ctm_sub_1" },
};

const checkoutConfig = {
  provider: "paddle",
  price_id: "pri_test_growth",
  client_token: "test_client_token",
  environment: "production",
  customer: { email: "owner@example.com" },
  custom_data: { organization_id: 7, plan_slug: "growth" },
};

const livePlans = {
  plans: [
    { slug: "starter", name: "Starter", is_featured: false },
    { slug: "growth", name: "Growth", is_featured: true },
    { slug: "agency", name: "Agency", is_featured: false },
    { slug: "agency_pro", name: "Agency Pro", is_featured: false },
  ],
};

function paddleStub() {
  return {
    Checkout: { open: vi.fn(), close: vi.fn() },
    Environment: { set: vi.fn() },
    Initialized: true,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  resetPaddleForTests();
  apiMocks.apiGet.mockImplementation((path: string) =>
    path === "/billing/subscription"
      ? Promise.resolve(freeOrg)
      : path === "/public/plans"
        ? Promise.resolve(livePlans)
        : Promise.resolve({}),
  );
});

describe("Billing page checkout", () => {
  it("formats cent-denominated plan prices and hides stale trial dates on active subscriptions", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription"
        ? Promise.resolve({
            ...paddleOrg,
            organization: { status: "active", trial_ends_at: "2026-07-29T00:00:00Z" },
            plan: { name: "Agency Pro", price_monthly: 29900 },
          })
        : path === "/public/plans"
          ? Promise.resolve(livePlans)
          : Promise.resolve({}),
    );

    render(<BillingPage />);

    expect(await screen.findByText("$299/mo")).toBeInTheDocument();
    expect(screen.queryByText(/trial ends/i)).not.toBeInTheDocument();
  });

  it("keeps the legacy Stripe redirect when Paddle is not enabled (501)", async () => {
    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/checkout-config"
        ? Promise.reject({ status: 501, message: "Paddle billing is not enabled.", data: { code: "paddle_not_enabled" } })
        : path === "/billing/checkout"
          ? Promise.resolve({ url: "https://checkout.stripe.com/c/pay/test" })
          : Promise.resolve({}),
    );

    render(<BillingPage />);
    const checkoutButton = await screen.findByRole("button", { name: "Continue to checkout" });

    await userEvent.selectOptions(screen.getByLabelText("Plan"), "agency");
    await userEvent.selectOptions(screen.getByLabelText("Billing interval"), "yearly");
    await userEvent.click(checkoutButton);

    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/checkout-config", { plan: "agency", kind: "yearly" }),
    );
    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/checkout", { plan: "agency", interval: "yearly" }),
    );
  });

  it("opens a Paddle overlay with the issued price/token and polls until the webhook projects it", async () => {
    const P = paddleStub();
    paddleMocks.initializePaddle.mockResolvedValue(P);

    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/checkout-config" ? Promise.resolve(checkoutConfig) : Promise.resolve({}),
    );

    // Call 1 is the page's own load. Call 2 is the post-payment poll, held
    // open so the "processing" state is observable. Later calls (reload)
    // already see the projected Paddle subscription.
    let resolvePoll: (value: any) => void = () => {};
    let subscriptionCalls = 0;
    apiMocks.apiGet.mockImplementation((path: string) => {
      if (path === "/billing/subscription") {
        subscriptionCalls += 1;
        if (subscriptionCalls === 1) return Promise.resolve(freeOrg);
        if (subscriptionCalls === 2) return new Promise((r) => { resolvePoll = r; });
        return Promise.resolve(paddleOrg);
      }
      if (path === "/public/plans") return Promise.resolve(livePlans);
      return Promise.resolve({});
    });

    render(<BillingPage />);
    await userEvent.click(await screen.findByRole("button", { name: "Continue to checkout" }));

    await waitFor(() => expect(paddleMocks.initializePaddle).toHaveBeenCalledWith({
      token: "test_client_token",
      environment: "production",
      eventCallback: expect.any(Function),
    }));

    await waitFor(() => expect(P.Checkout.open).toHaveBeenCalled());
    expect(P.Checkout.open).toHaveBeenCalledWith({
      items: [{ priceId: "pri_test_growth", quantity: 1 }],
      customer: { email: "owner@example.com" },
      customData: { organization_id: 7, plan_slug: "growth" },
      settings: {
        displayMode: "overlay",
        variant: "one-page",
        successUrl: `${window.location.origin}/dashboard/billing?checkout=success`,
      },
    });

    // The buyer pays inside the overlay: the SDK event callback fires.
    const eventCallback = paddleMocks.initializePaddle.mock.calls[0][0].eventCallback;
    act(() => { eventCallback({ name: "checkout.completed" }); });

    expect(await screen.findByText("Finishing your subscription setup…")).toBeInTheDocument();

    resolvePoll(paddleOrg);
    expect(await screen.findByText("You're on Growth — everything is active.")).toBeInTheDocument();
  });

  it("prefers live catalog names and featured flags from /public/plans", async () => {
    render(<BillingPage />);

    // growth is_featured in the live catalog → "Best Value" suffix.
    expect(await screen.findByRole("option", { name: "Growth — Best Value" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Agency Pro" })).toBeInTheDocument();
  });

  it("opens the Angel Solo lifetime checkout via checkout-config {plan: angel_solo, kind: lifetime}", async () => {
    const P = paddleStub();
    paddleMocks.initializePaddle.mockResolvedValue(P);

    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/checkout-config"
        ? Promise.resolve({
            ...checkoutConfig,
            price_id: "pri_test_angel_solo",
            custom_data: { organization_id: 7, purchase_kind: "lifetime", plan_slug: "angel_solo" },
          })
        : Promise.resolve({}),
    );

    render(<BillingPage />);
    // The tier's display name is Angel Solo (brand-assets handoff); the slug
    // in the checkout envelope below is what actually pins angel_solo.
    await userEvent.click(await screen.findByRole("button", { name: "Buy Angel Solo — $149" }));

    // One-time LTD purchase: same envelope, kind 'lifetime'.
    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/checkout-config", { plan: "angel_solo", kind: "lifetime" }),
    );

    await waitFor(() => expect(P.Checkout.open).toHaveBeenCalled());
    expect(P.Checkout.open).toHaveBeenCalledWith(
      expect.objectContaining({
      items: [{ priceId: "pri_test_angel_solo", quantity: 1 }],
      customData: { organization_id: 7, purchase_kind: "lifetime", plan_slug: "angel_solo" },
      }),
    );
  });

  it("sends the selected credit pack to the server before opening the popup", async () => {
    const P = paddleStub();
    paddleMocks.initializePaddle.mockResolvedValue(P);

    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/checkout-config"
        ? Promise.resolve({
            ...checkoutConfig,
            price_id: "pri_test_credits_20000",
            custom_data: { organization_id: 7, purchase_kind: "credit_pack", plan_slug: "insight_credits", credit_pack: "credits_20000" },
          })
        : Promise.resolve({}),
    );

    render(<BillingPage />);
    await userEvent.click(await screen.findByRole("button", { name: "Buy 20,000 credits — $49" }));

    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/checkout-config", {
        plan: "insight_credits",
        kind: "credit_pack",
        credit_pack: "credits_20000",
      }),
    );
    await waitFor(() => expect(P.Checkout.open).toHaveBeenCalledWith(expect.objectContaining({
      items: [{ priceId: "pri_test_credits_20000", quantity: 1 }],
    })));
  });
});
