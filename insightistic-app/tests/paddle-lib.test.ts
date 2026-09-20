import { describe, it, expect, vi, beforeEach } from "vitest";
import { initPaddle, initializeWith, openPaddleCheckout } from "@/lib/paddle";
import type { PaddleJs } from "@/lib/paddle";

/**
 * The Paddle.js loader must behave like a singleton: one script tag no matter
 * how many times init runs, sandbox selected before Initialize, and checkout
 * events routed to whoever opened the latest checkout. Everything runs against
 * a fake Paddle global — the CDN script is never fetched in jsdom; the loader
 * is resolved by dispatching its load event.
 */

function fakePaddle() {
  return {
    Environment: { set: vi.fn() },
    Initialize: vi.fn(),
    Checkout: {
      open: vi.fn(),
      on: vi.fn(),
      isCompleted: vi.fn(() => true),
    },
  };
}

/** The CDN never loads in jsdom — fire the load event the loader listens for. */
function resolveLoader() {
  document.getElementById("paddle-js")?.dispatchEvent(new Event("load"));
}

beforeEach(() => {
  vi.clearAllMocks();
  document.getElementById("paddle-js")?.remove();
  delete (window as any).Paddle;
});

describe("paddle lib", () => {
  it("injects the Paddle.js script exactly once, even when init runs twice", async () => {
    const first = initPaddle("tok_once", "production").catch(() => "rejected");
    const second = initPaddle("tok_once", "production").catch(() => "rejected");

    expect(document.querySelectorAll("script#paddle-js")).toHaveLength(1);
    expect(document.getElementById("paddle-js")?.getAttribute("src")).toBe(
      "https://cdn.paddle.com/paddle/v2/paddle.js",
    );

    resolveLoader();
    expect(await first).toBe(await second);
  });

  it("selects the sandbox environment before Initialize", async () => {
    const P = fakePaddle();
    (window as any).Paddle = P;

    const done = initPaddle("tok_sandbox", "sandbox");
    resolveLoader();
    await done;

    expect(P.Environment.set).toHaveBeenCalledWith("sandbox");
    expect(P.Initialize).toHaveBeenCalledWith({ token: "tok_sandbox", environment: "sandbox" });
    // Order is the whole point: sandbox mode must be set before Initialize.
    expect(P.Environment.set.mock.invocationCallOrder[0]).toBeLessThan(
      P.Initialize.mock.invocationCallOrder[0],
    );
  });

  it("initializes production without forcing the environment", async () => {
    const P = fakePaddle();
    (window as any).Paddle = P;

    const done = initPaddle("tok_prod", "production");
    resolveLoader();
    await done;

    expect(P.Environment.set).not.toHaveBeenCalled();
    expect(P.Initialize).toHaveBeenCalledWith({ token: "tok_prod", environment: "production" });
  });

  it("runs Initialize only once per token+environment", async () => {
    const P = fakePaddle();
    (window as any).Paddle = P;

    const first = initPaddle("tok_cache", "sandbox");
    const second = initPaddle("tok_cache", "sandbox");
    resolveLoader();

    const [a, b] = await Promise.all([first, second]);
    expect(P.Initialize).toHaveBeenCalledTimes(1);
    expect(a).toBe(b);
  });

  it("initializeWith (the factored init) keeps the sandbox gate in order", () => {
    const sandbox = fakePaddle();
    initializeWith(sandbox as unknown as PaddleJs, "tok_direct", "sandbox");
    expect(sandbox.Environment.set.mock.invocationCallOrder[0]).toBeLessThan(
      sandbox.Initialize.mock.invocationCallOrder[0],
    );
    expect(sandbox.Initialize).toHaveBeenCalledWith({ token: "tok_direct", environment: "sandbox" });

    const production = fakePaddle();
    initializeWith(production as unknown as PaddleJs, "tok_direct", "production");
    expect(production.Environment.set).not.toHaveBeenCalled();
  });

  it("opens an overlay checkout with the API-issued price and routes completion events", () => {
    const P = fakePaddle();
    (window as any).Paddle = P;
    const onSuccess = vi.fn();
    const onClose = vi.fn();

    openPaddleCheckout({
      priceId: "pri_test_growth",
      email: "owner@example.com",
      customData: { organization_id: 7, plan_slug: "growth" },
      onSuccess,
      onClose,
    });

    expect(P.Checkout.open).toHaveBeenCalledWith({
      items: [{ priceId: "pri_test_growth", quantity: 1 }],
      customer: { email: "owner@example.com" },
      customData: { organization_id: 7, plan_slug: "growth" },
      settings: {
        displayMode: "overlay",
        successUrl: `${window.location.origin}/dashboard/billing?checkout=success`,
      },
    });

    const fire = (event: string) =>
      P.Checkout.on.mock.calls.filter(([e]) => e === event).forEach(([, cb]) => (cb as () => void)());

    fire("checkout.completed");
    fire("checkout.closed");

    expect(onSuccess).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledWith(true);
  });

  it("reports an abandoned checkout as not completed", () => {
    const P = fakePaddle();
    P.Checkout.isCompleted = vi.fn(() => false);
    (window as any).Paddle = P;
    const onClose = vi.fn();

    openPaddleCheckout({ priceId: "pri_test_2", onSuccess: vi.fn(), onClose });

    const closed = P.Checkout.on.mock.calls.find(([e]) => e === "checkout.closed")?.[1] as () => void;
    closed();

    expect(onClose).toHaveBeenCalledWith(false);
  });

  it("omits the customer when no email is provided", () => {
    const P = fakePaddle();
    (window as any).Paddle = P;

    openPaddleCheckout({ priceId: "pri_test_3", onSuccess: vi.fn(), onClose: vi.fn() });

    expect(P.Checkout.open).toHaveBeenCalledWith(expect.objectContaining({ customer: undefined }));
  });
});
