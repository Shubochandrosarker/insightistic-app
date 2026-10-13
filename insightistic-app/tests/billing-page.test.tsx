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

/** Agency Pro subscriber — the top tier: no lower plan may be buyable. */
const agencyProOrg = {
  organization: { status: "active" },
  plan: { name: "Agency Pro", slug: "agency_pro" },
  usage: { ai_insights: { used: 2, limit: 3000 }, reports: { used: 1, limit: 300 } },
  subscription: { billing_provider: "paddle", provider_subscription_id: "ctm_sub_9" },
};

/** Founder tier 3 (Angel Agency) lifetime owner. */
const founderTier3Org = {
  organization: { status: "active" },
  plan: { name: "Founder Tier 3", slug: "founder_tier_3" },
  usage: { ai_insights: { used: 0, limit: 0 }, reports: { used: 0, limit: 100 } },
  subscription: {
    billing_provider: "paddle",
    provider_subscription_id: "ctm_sub_5",
    lifetime_grant: { active: true, plan_slug: "founder_tier_3" },
  },
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

  it("opens the founder lifetime checkout via checkout-config {plan: founder_tier_1, kind: lifetime}", async () => {
    const P = paddleStub();
    (window as any).Paddle = P;

    apiMocks.apiPost.mockImplementation((path: string) =>
      path === "/billing/checkout-config"
        ? Promise.resolve({
            ...checkoutConfig,
            price_id: "pri_test_founder1",
            custom_data: { organization_id: 7, purchase_kind: "lifetime", plan_slug: "founder_tier_1" },
          })
        : Promise.resolve({}),
    );

    render(<BillingPage />);
    // The tier's display name is Angel Solo (brand-assets handoff); the slug
    // in the checkout envelope below is what actually pins founder_tier_1.
    await userEvent.click(await screen.findByRole("button", { name: "Buy Angel Solo — $79" }));

    // One-time LTD purchase: same envelope, kind 'lifetime'. Slugs already
    // differ per tier, so no variant is sent.
    await waitFor(() =>
      expect(apiMocks.apiPost).toHaveBeenCalledWith("/billing/checkout-config", { plan: "founder_tier_1", kind: "lifetime" }),
    );

    // The hosted pay-domain iframe carries the checkout (Paddle only creates
    // transactions on the approved domain).
    await waitFor(() => expect(document.getElementById("insightistic-checkout-frame")).toBeTruthy());
    const frame = document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe");
    const src = new URL(frame!.src);
    expect(src.searchParams.get("price")).toBe("pri_test_founder1");
    expect(JSON.parse(src.searchParams.get("data")!)).toEqual({
      organization_id: 7,
      purchase_kind: "lifetime",
      plan_slug: "founder_tier_1",
    });
  });

  // Founder order #1/#2 (2026-10-13): every pack button used to fire the
  // identical {plan: insight_credits, kind: credit_pack} — the backend's
  // first() always resolved the same $15 price. Each button now carries its
  // variant and must open ITS OWN price.
  it("passes the pack variant so each credit pack opens its own checkout", async () => {
    const P = paddleStub();
    (window as any).Paddle = P;

    const packPrices: Record<string, string> = {
      credits_5000: "pri_01m2y7dxpgpp7sajjt9maky1j7",
      credits_20000: "pri_01m2y7dymwecszpnpc511geb35",
      credits_50000: "pri_01m2y7dzdf1j627w2tbmx0nr52",
    };
    apiMocks.apiPost.mockImplementation((path: string, body: any) =>
      path === "/billing/checkout-config"
        ? Promise.resolve({
            ...checkoutConfig,
            price_id: packPrices[body.variant],
            custom_data: { organization_id: 7, purchase_kind: "credit_pack", plan_slug: "insight_credits", variant: body.variant },
          })
        : Promise.resolve({}),
    );

    render(<BillingPage />);

    // Each button sends a DISTINCT variant — no shared plan+kind envelope.
    await userEvent.click(await screen.findByRole("button", { name: "Buy 5,000 credits — $15" }));
    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenLastCalledWith("/billing/checkout-config", {
      plan: "insight_credits", kind: "credit_pack", variant: "credits_5000",
    }));
    resolvePaddleLoad();
    await waitFor(() => expect(document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe")?.src).toContain(packPrices.credits_5000));

    // Close the open overlay so the next button is clickable again (busy
    // resets on an abandoned close).
    await userEvent.click(screen.getByRole("button", { name: "Close checkout" }));

    await userEvent.click(await screen.findByRole("button", { name: "Buy 20,000 credits — $49" }));
    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenLastCalledWith("/billing/checkout-config", {
      plan: "insight_credits", kind: "credit_pack", variant: "credits_20000",
    }));
    resolvePaddleLoad();
    await waitFor(() => expect(document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe")?.src).toContain(packPrices.credits_20000));
    await userEvent.click(screen.getByRole("button", { name: "Close checkout" }));

    await userEvent.click(await screen.findByRole("button", { name: "Buy 50,000 credits — $99" }));
    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenLastCalledWith("/billing/checkout-config", {
      plan: "insight_credits", kind: "credit_pack", variant: "credits_50000",
    }));
    resolvePaddleLoad();
    await waitFor(() => expect(document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe")?.src).toContain(packPrices.credits_50000));
  });

  // Founder order #3: each lifetime button opens ITS OWN tier checkout.
  it("opens a distinct checkout per founder tier", async () => {
    const P = paddleStub();
    (window as any).Paddle = P;

    const tierPrices: Record<string, string> = {
      founder_tier_1: "pri_01m2y7dw3kd8162wzm54j6k7z3",
      founder_tier_2: "pri_01m2y7dwmfewpennma8gjffm88",
      founder_tier_3: "pri_01m2y7dx628b2ahgzf2vqafey4",
    };
    apiMocks.apiPost.mockImplementation((path: string, body: any) =>
      path === "/billing/checkout-config"
        ? Promise.resolve({
            ...checkoutConfig,
            price_id: tierPrices[body.plan],
            custom_data: { organization_id: 7, purchase_kind: "lifetime", plan_slug: body.plan },
          })
        : Promise.resolve({}),
    );

    render(<BillingPage />);

    await userEvent.click(await screen.findByRole("button", { name: "Buy Angel Studio — $149" }));
    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenLastCalledWith("/billing/checkout-config", { plan: "founder_tier_2", kind: "lifetime" }));
    resolvePaddleLoad();
    await waitFor(() => expect(document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe")?.src).toContain(tierPrices.founder_tier_2));
    await userEvent.click(screen.getByRole("button", { name: "Close checkout" }));

    await userEvent.click(await screen.findByRole("button", { name: "Buy Angel Agency — $249" }));
    await waitFor(() => expect(apiMocks.apiPost).toHaveBeenLastCalledWith("/billing/checkout-config", { plan: "founder_tier_3", kind: "lifetime" }));
    resolvePaddleLoad();
    await waitFor(() => expect(document.querySelector<HTMLIFrameElement>("#insightistic-checkout-frame iframe")?.src).toContain(tierPrices.founder_tier_3));
  });

  // Founder order #4: a paid user must not be shown lower-plan purchases.
  it("hides lower plans from an Agency Pro subscriber (top tier)", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription"
        ? Promise.resolve(agencyProOrg)
        : path === "/public/plans"
          ? Promise.resolve(livePlans)
          : Promise.resolve({}),
    );

    render(<BillingPage />);

    expect(await screen.findByText(/top plan/i)).toBeInTheDocument();
    expect(screen.queryByLabelText("Plan")).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Continue to checkout" })).not.toBeInTheDocument();
    // Lifetime tiers sit at-or-below Agency Pro, so their buy buttons go too.
    expect(screen.queryByRole("button", { name: /Buy Angel/ })).not.toBeInTheDocument();
  });

  it("shows an Agency subscriber only Agency Pro as an upgrade", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription"
        ? Promise.resolve({
            ...agencyProOrg,
            plan: { name: "Agency", slug: "agency" },
          })
        : path === "/public/plans"
          ? Promise.resolve(livePlans)
          : Promise.resolve({}),
    );

    render(<BillingPage />);

    // Only the tier above (Agency Pro) is selectable; Starter/Growth are gone.
    const options = await screen.findAllByRole("option");
    const names = options.map((o) => o.textContent);
    expect(names).toContain("Agency Pro");
    expect(names).not.toContain("Starter");
    expect(names).not.toContain("Growth");
    expect(names).not.toContain("Agency");
    // No lifetime buy buttons at-or-below Agency (tiers 1-2).
    expect(screen.queryByRole("button", { name: "Buy Angel Solo — $79" })).not.toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "Buy Angel Studio — $149" })).not.toBeInTheDocument();
  });

  it("shows a founder tier 3 owner no lower lifetime tiers", async () => {
    apiMocks.apiGet.mockImplementation((path: string) =>
      path === "/billing/subscription"
        ? Promise.resolve(founderTier3Org)
        : path === "/public/plans"
          ? Promise.resolve(livePlans)
          : Promise.resolve({}),
    );

    render(<BillingPage />);

    expect(await screen.findByText(/Lifetime Tier 3 active/i)).toBeInTheDocument();
    // The grant branch replaces the whole buy grid.
    expect(screen.queryByRole("button", { name: /Buy Angel/ })).not.toBeInTheDocument();
    // Credit packs remain purchasable for lifetime owners.
    expect(await screen.findByRole("button", { name: "Buy 5,000 credits — $15" })).toBeInTheDocument();
  });
});
