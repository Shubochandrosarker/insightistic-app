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

vi.mock("@/lib/api", () => apiMocks);

vi.mock("@/lib/dashboard", () => ({
  useDashboard: () => ({ sites: [{ id: 1, name: "Test Shop" }] }),
}));

import BillingPage from "@/app/dashboard/billing/page";
import { teardownMessageListenerForTest } from "@/lib/paddle";

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
    Environment: { set: vi.fn() },
    Initialize: vi.fn(),
    Checkout: { open: vi.fn() },
  };
}

/** The CDN never loads in jsdom — fire the load event the loader listens for. */
function resolvePaddleLoad() {
  document.getElementById("paddle-js")?.dispatchEvent(new Event("load"));
}

/** Simulate the pay-host iframe reporting payment completion. */
function checkoutFrameReportsCompleted() {
  window.dispatchEvent(
    new MessageEvent("message", {
      data: { type: "insightistic-checkout-completed" },
      origin: "https://pay.insightistic.com",
    }),
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  window.history.pushState({}, "", "/dashboard/billing");
  document.getElementById("paddle-js")?.remove();
  document.getElementById("insightistic-checkout-frame")?.remove();
  delete (window as any).Paddle;
  teardownMessageListenerForTest();
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

  it("offers Stripe when the API lists it and redirects to a Stripe Checkout Session", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription" ? Promise.resolve(freeOrg)
      : path === "/public/plans" ? Promise.resolve(livePlans)
      : path === "/billing/providers" ? Promise.resolve({ providers: [{ id: "paddle" }, { id: "stripe" }] })
      : path === "/billing/summary" ? Promise.resolve({ provider: "stripe", portal: { type: "stripe_portal", available: true },
          invoices: [{ id: 1, date: "2026-11-04T00:00:00Z", total_minor: 6900, status: "completed", url: "https://invoice.stripe.com/i/test" }] })
      : Promise.resolve({}),
    );
    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/stripe/checkout" ? Promise.resolve({ url: "https://checkout.stripe.com/c/pay/cs_test_x" }) : Promise.resolve({}),
    );
    const assign = vi.fn();
    const originalLocation = window.location;
    Object.defineProperty(window, "location", { configurable: true, value: { ...window.location, search: "", set href(v: string) { assign(v); } } });

    try {
    render(<BillingPage />);
    await userEvent.click(await screen.findByLabelText("Card via Stripe"));
    expect(await screen.findByText("View ↗")).toHaveAttribute("href", "https://invoice.stripe.com/i/test");
    expect(screen.queryByText("Manage subscription ↗")).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Continue to checkout" }));

    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/stripe/checkout", { plan: "growth", kind: "monthly" }));
    expect(apiMocks.apiPost).not.toHaveBeenCalledWith("/billing/checkout-config", expect.anything());
    expect(assign).toHaveBeenCalledWith("https://checkout.stripe.com/c/pay/cs_test_x");
    } finally {
      Object.defineProperty(window, "location", { configurable: true, value: originalLocation });
    }
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

  it("opens a checkout on the approved pay domain and polls until the webhook projects it", async () => {
    const P = paddleStub();
    (window as any).Paddle = P;

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

    // Loader injects the CDN script; jsdom resolves it by event.
    await waitFor(() => expect(document.getElementById("paddle-js")).toBeTruthy());
    resolvePaddleLoad();

    // Initialize takes only the token — passing `environment` throws
    // "[PADDLE] Unknown option parameter 'environment'" in Paddle.js v2.
    await waitFor(() => expect(P.Initialize).toHaveBeenCalledWith({ token: "test_client_token" }));
    expect(P.Environment.set).not.toHaveBeenCalled();

    // The transaction is created by the hosted pay-domain iframe (Paddle
    // rejects checkout creation from app.insightistic.com — 403 blocked
    // vendor). The dashboard only forwards the config into the frame.
    await waitFor(() => expect(document.getElementById("insightistic-checkout-frame")).toBeTruthy());
    const frame = document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe");
    const src = new URL(frame!.src);
    expect(src.origin).toBe("https://pay.insightistic.com");
    expect(src.searchParams.get("price")).toBe("pri_test_growth");
    expect(JSON.parse(src.searchParams.get("data")!)).toEqual({ organization_id: 7, plan_slug: "growth" });

    // The buyer pays inside the frame; it reports completion over postMessage.
    checkoutFrameReportsCompleted();

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
    (window as any).Paddle = P;

    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/checkout-config"
        ? Promise.resolve({
            ...checkoutConfig,
            price_id: "pri_test_angel_solo",
            custom_data: { organization_id: 7, purchase_kind: "lifetime", plan_slug: "angel_solo" },
          })
        : Promise.resolve({}),
    );

    // /ltd is direct checkout: arriving with ?plan=angel_solo opens the
    // one-time checkout immediately, without a click or a trial.
    window.history.pushState({}, "", "/dashboard/billing?plan=angel_solo");
    render(<BillingPage />);

    // One-time LTD purchase: same envelope, kind 'lifetime'.
    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/checkout-config", { plan: "angel_solo", kind: "lifetime" }),
    );

    // The hosted pay-domain iframe carries the checkout (Paddle only creates
    // transactions on the approved domain).
    await waitFor(() => expect(document.getElementById("insightistic-checkout-frame")).toBeTruthy());
    const frame = document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe");
    const src = new URL(frame!.src);
    expect(src.searchParams.get("price")).toBe("pri_test_angel_solo");
    expect(JSON.parse(src.searchParams.get("data")!)).toEqual({
      organization_id: 7,
      purchase_kind: "lifetime",
      plan_slug: "angel_solo",
    });
  });

  it("sends the selected credit pack to the server before opening the popup", async () => {
    const P = paddleStub();
    (window as any).Paddle = P;

    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/checkout-config"
        ? Promise.resolve({
            ...checkoutConfig,
            price_id: "pri_test_credits_20000",
            custom_data: {
              organization_id: 7,
              purchase_kind: "credit_pack",
              plan_slug: "insight_credits",
              credit_pack: "credits_20000",
            },
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

    await waitFor(() => expect(document.getElementById("insightistic-checkout-frame")).toBeTruthy());
    const frame = document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe");
    const src = new URL(frame!.src);
    expect(src.searchParams.get("price")).toBe("pri_test_credits_20000");
    expect(JSON.parse(src.searchParams.get("data")!)).toEqual({
      organization_id: 7,
      purchase_kind: "credit_pack",
      plan_slug: "insight_credits",
      credit_pack: "credits_20000",
    });
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
    expect(apiMocks.apiPost).not.toHaveBeenCalledWith("/billing/checkout-config", expect.anything());
    expect(document.getElementById("insightistic-checkout-frame")).toBeNull();
  });

  it("does not list Founding Angel tiers in-app unless arriving from /ltd", async () => {
    render(<BillingPage />);
    await screen.findByText("Change plan");
    expect(screen.queryByRole("button", { name: /Buy Angel/ })).not.toBeInTheDocument();
    expect(screen.getAllByRole("button", { name: /Buy .* credits/ }).length).toBeGreaterThan(0);
  });
});
