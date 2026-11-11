import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";

/**
 * Billing page, Stripe-only (Paddle surface retired):
 *  - every checkout — subscription, founder lifetime, credit packs — is a
 *    redirect to a server-created Stripe Checkout Session (subscription and
 *    lifetime via /billing/stripe/checkout, packs via /billing/credits/checkout);
 *  - completion is polled server-side after the ?checkout=success return;
 *    the webhook, never the browser callback, makes a purchase real;
 *  - the portal button POSTs /billing/portal (Stripe Customer Portal for all).
 */

const apiMocks = vi.hoisted(() => ({
  apiGet: vi.fn(),
  apiPost: vi.fn(),
}));

vi.mock("@/lib/api", () => apiMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ sites: [{ id: 1, name: "Test Shop" }] }),
}));

import BillingPage from "@/app/dashboard/billing/page";

const freeOrg = {
  organization: { status: "trialing" },
  plan: { name: "Free trial" },
  usage: { ai_insights: { used: 0, limit: 15 }, reports: { used: 0, limit: 4 } },
  subscription: {},
};

const legacyPaddleOrg = {
  organization: { status: "active" },
  plan: { name: "Growth" },
  usage: { ai_insights: { used: 2, limit: 75 }, reports: { used: 1, limit: 10 } },
  subscription: { billing_provider: "paddle", provider_subscription_id: "ctm_sub_1" },
};

const livePlans = {
  plans: [
    { slug: "starter", name: "Starter", is_featured: false },
    { slug: "growth", name: "Growth", is_featured: true },
    { slug: "agency", name: "Agency", is_featured: false },
    { slug: "agency_pro", name: "Agency Pro", is_featured: false },
  ],
};

const defaultGet = (path: string) =>
  path === "/billing/subscription"
    ? Promise.resolve(freeOrg)
    : path === "/public/plans"
      ? Promise.resolve(livePlans)
      : Promise.resolve({});

/** Swap window.location so `window.location.href = ...` is observable. */
function stubLocation(search = "") {
  const assign = vi.fn();
  const originalLocation = window.location;
  Object.defineProperty(window, "location", {
    configurable: true,
    value: { ...window.location, search, set href(v: string) { assign(v); } },
  });
  return { assign, restore: () => Object.defineProperty(window, "location", { configurable: true, value: originalLocation }) };
}

beforeEach(() => {
  vi.clearAllMocks();
  window.history.pushState({}, "", "/dashboard/billing");
  apiMocks.apiGet.mockImplementation(defaultGet);
});

describe("Billing page checkout", () => {
  it("formats cent-denominated plan prices and hides stale trial dates on active subscriptions", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription"
        ? Promise.resolve({
            ...legacyPaddleOrg,
            organization: { status: "active", trial_ends_at: "2026-07-29T00:00:00Z" },
            plan: { name: "Agency Pro", price_monthly: 29900 },
          })
        : path === "/public/plans"
          ? Promise.resolve(livePlans)
          : Promise.resolve({}),
    );

    render(<BillingPage />);

    expect(await screen.findByText((content) => content.replace(/\s+/g, "") === "$299/mo")).toBeInTheDocument();
    expect(screen.queryByText(/trial ends/i)).not.toBeInTheDocument();
  });

  it("shows the catalog allowance and only higher plans for an Angel account", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription"
        ? Promise.resolve({
            organization: { status: "active" },
            plan: {
              slug: "angel_studio",
              name: "Angel Studio",
              price_monthly: 29900,
              limits: { billing_model: "lifetime", monthly_ai_credits: 8000 },
            },
            usage: { ai_insights: { used: 9, limit: 100 }, reports: { used: 0, limit: 25 } },
            credits: {
              monthly_included: { granted: 8000, remaining: 6500 },
              purchased: { remaining: 0 },
              total_usable: 6500,
            },
            subscription: {},
          })
        : path === "/public/plans"
          ? Promise.resolve(livePlans)
          : Promise.resolve({}),
    );

    render(<BillingPage />);

    expect(await screen.findByText((content) => content.includes("one time"))).toBeInTheDocument();
    expect(screen.getByText("Included this month")).toBeInTheDocument();
    expect(screen.getByText("Included remaining")).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Agency" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Agency Pro" })).toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "Starter" })).not.toBeInTheDocument();
    expect(screen.queryByRole("option", { name: "Growth — Best Value" })).not.toBeInTheDocument();
  });

  it("redirects checkout to a Stripe Checkout Session and shows invoices without any Paddle portal link", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription" ? Promise.resolve(freeOrg)
      : path === "/public/plans" ? Promise.resolve(livePlans)
      : path === "/billing/summary" ? Promise.resolve({ provider: "stripe", portal: { type: "stripe_portal", available: true },
          invoices: [{ id: 1, date: "2026-11-04T00:00:00Z", total_minor: 6900, status: "completed", url: "https://invoice.stripe.com/i/test" }] })
      : Promise.resolve({}),
    );
    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/stripe/checkout" ? Promise.resolve({ url: "https://checkout.stripe.com/c/pay/cs_test_x" }) : Promise.resolve({}),
    );
    const { assign, restore } = stubLocation();

    try {
      render(<BillingPage />);
      expect(await screen.findByText("View ↗")).toHaveAttribute("href", "https://invoice.stripe.com/i/test");
      expect(screen.queryByText("Manage subscription ↗")).not.toBeInTheDocument();

      await userEvent.click(screen.getByRole("button", { name: "Continue to checkout" }));

      await waitFor(() => expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/stripe/checkout", { plan: "growth", kind: "monthly" }));
      expect(apiMocks.apiPost).not.toHaveBeenCalledWith("/billing/checkout-config", expect.anything());
      expect(apiMocks.apiPost).not.toHaveBeenCalledWith("/billing/credits/checkout", expect.anything());
      expect(assign).toHaveBeenCalledWith("https://checkout.stripe.com/c/pay/cs_test_x");
    } finally {
      restore();
    }
  });

  it("sends the selected plan and interval to the Stripe checkout", async () => {
    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/stripe/checkout"
          ? Promise.resolve({ url: "https://checkout.stripe.com/c/pay/test" })
          : Promise.resolve({}),
    );

    render(<BillingPage />);
    const checkoutButton = await screen.findByRole("button", { name: "Continue to checkout" });

    await userEvent.selectOptions(screen.getByLabelText("Plan"), "agency");
    await userEvent.selectOptions(screen.getByLabelText("Billing interval"), "yearly");
    await userEvent.click(checkoutButton);

    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/stripe/checkout", { plan: "agency", kind: "yearly" }),
    );
  });

  it("opens the Angel Solo lifetime purchase on Stripe with no provider toggle and no trial", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription" ? Promise.resolve(freeOrg)
      : path === "/public/plans" ? Promise.resolve(livePlans)
      : Promise.resolve({}),
    );

    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/stripe/checkout"
        ? Promise.resolve({ url: "https://checkout.stripe.com/c/pay/cs_test_ltd" })
        : Promise.resolve({}),
    );

    const { assign, restore } = stubLocation("?plan=angel_solo");
    try {
      render(<BillingPage />);
      expect(screen.queryByRole("button", { name: /Start 7-day free trial/i })).not.toBeInTheDocument();
      expect((await screen.findAllByText(/No free trial/i)).length).toBeGreaterThan(0);
      expect(screen.queryByLabelText(/Paddle/i)).not.toBeInTheDocument();

      const payButton = await screen.findByRole("button", { name: "Pay $149" });
      await userEvent.click(payButton);
      await waitFor(() => expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/stripe/checkout", { plan: "angel_solo", kind: "lifetime" }));
      expect(assign).toHaveBeenCalledWith("https://checkout.stripe.com/c/pay/cs_test_ltd");
    } finally {
      restore();
    }
  });

  it("sends the selected credit pack to the Stripe credits checkout", async () => {
    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/credits/checkout"
        ? Promise.resolve({ url: "https://checkout.stripe.com/c/pay/cs_test_credits" })
        : Promise.resolve({}),
    );

    const { assign, restore } = stubLocation();
    try {
      render(<BillingPage />);
      await userEvent.click(await screen.findByRole("button", { name: "Buy 20,000 credits — $49" }));

      await waitFor(() =>
        expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/credits/checkout", { pack: "credits_20000" }),
      );
      expect(apiMocks.apiPost).not.toHaveBeenCalledWith("/billing/checkout-config", expect.anything());
      expect(assign).toHaveBeenCalledWith("https://checkout.stripe.com/c/pay/cs_test_credits");
    } finally {
      restore();
    }
  });

  it("shows the credits confirmation message when Stripe credits checkout returns ?credits=success", async () => {
    window.history.pushState({}, "", "/dashboard/billing?credits=success&pack=credits_20000");

    render(<BillingPage />);

    expect(await screen.findByText(/your credits land after checkout completes/i)).toBeInTheDocument();
    expect(apiMocks.apiPost).not.toHaveBeenCalled();
  });

  it("starts a no-payment 7-day trial for an eligible account instead of opening checkout", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription"
        ? Promise.resolve({ ...freeOrg, organization: { status: "active" }, plan: { slug: "free", name: "Free Connected" }, trial: { eligible: true, days: 7 } })
        : path === "/public/plans" ? Promise.resolve(livePlans) : Promise.resolve({}),
    );
    apiMocks.apiPost.mockResolvedValue({ ok: true, organization: { status: "trialing", trial_ends_at: "2026-11-03T00:00:00Z" } });

    render(<BillingPage />);
    await userEvent.click(await screen.findByRole("button", { name: "Start 7-day free trial" }));

    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/start-trial", { plan: "growth" }));
    expect(apiMocks.apiPost).not.toHaveBeenCalledWith("/billing/stripe/checkout", expect.anything());
  });

  it("prefers live catalog names and featured flags from /public/plans", async () => {
    render(<BillingPage />);

    // growth is_featured in the live catalog → "Best Value" suffix.
    expect(await screen.findByRole("option", { name: "Growth — Best Value" })).toBeInTheDocument();
    expect(screen.getByRole("option", { name: "Agency Pro" })).toBeInTheDocument();
  });

  it("does not list Founding Angel tiers in-app unless arriving from /ltd", async () => {
    render(<BillingPage />);
    await screen.findByText("Change plan");
    expect(screen.queryByRole("button", { name: /Buy Angel/ })).not.toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /Buy .* credits/ }).length).toBeGreaterThan(0);
  });
});
