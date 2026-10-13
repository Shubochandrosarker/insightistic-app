import { describe, it, expect, vi, beforeEach } from "vitest";
import {
  initPaddle,
  initializeWith,
  openPaddleCheckout,
  teardownMessageListenerForTest,
  CHECKOUT_HOST_URL,
  MSG_OPEN,
  MSG_COMPLETED,
  MSG_CLOSED,
} from "@/lib/paddle";
import type { PaddleJs } from "@/lib/paddle";

/**
 * The Paddle.js loader must behave like a singleton: one script tag no matter
 * how many times init runs, sandbox selected before Initialize. Checkout
 * itself is delegated to the hosted pay-domain iframe (Paddle rejects
 * transaction creation from non-approved domains), so openPaddleCheckout is
 * tested as an iframe + postMessage bridge, not against the Paddle global.
 * Everything runs against a fake Paddle global — the CDN script is never
 * fetched in jsdom; the loader is resolved by dispatching its load event.
 */

function fakePaddle() {
  return {
    Environment: { set: vi.fn() },
    Initialize: vi.fn(),
    Checkout: {
      open: vi.fn(),
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
  document.getElementById("insightistic-checkout-frame")?.remove();
  delete (window as any).Paddle;
  teardownMessageListenerForTest();
});

/** Simulate a message arriving from the pay-host iframe. */
function postFromCheckout(data: unknown) {
  window.dispatchEvent(new MessageEvent("message", { data, origin: CHECKOUT_HOST_URL }));
}

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
    // Initialize takes only the token — Paddle.js v2 throws on unknown
    // options, so `environment` must never be passed to Initialize.
    expect(P.Initialize).toHaveBeenCalledWith({ token: "tok_sandbox" });
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
    expect(P.Initialize).toHaveBeenCalledWith({ token: "tok_prod" });
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
    expect(sandbox.Initialize).toHaveBeenCalledWith({ token: "tok_direct" });

    const production = fakePaddle();
    initializeWith(production as unknown as PaddleJs, "tok_direct", "production");
    expect(production.Environment.set).not.toHaveBeenCalled();
  });

  it("opens the checkout on the approved pay domain as a hosted iframe", () => {
    openPaddleCheckout({
      priceId: "pri_test_growth",
      email: "owner@example.com",
      customData: { organization_id: 7, plan_slug: "growth" },
      onSuccess: vi.fn(),
      onClose: vi.fn(),
    });

    const wrap = document.getElementById("insightistic-checkout-frame");
    expect(wrap).toBeTruthy();
    const frame = wrap!.querySelector("iframe");
    expect(frame).toBeTruthy();
    const src = new URL(frame!.getAttribute("src")!);
    expect(src.origin).toBe(CHECKOUT_HOST_URL);
    expect(src.searchParams.get("price")).toBe("pri_test_growth");
    expect(src.searchParams.get("email")).toBe("owner@example.com");
    expect(JSON.parse(src.searchParams.get("data")!)).toEqual({ organization_id: 7, plan_slug: "growth" });
    expect((frame as HTMLIFrameElement).allow).toContain("payment");
  });

  it("routes completion + close messages from the pay host to the opener", () => {
    const onSuccess = vi.fn();
    const onClose = vi.fn();
    openPaddleCheckout({ priceId: "pri_test_1", onSuccess, onClose });

    // Foreign-origin messages are ignored (never trust other frames).
    window.dispatchEvent(new MessageEvent("message", { data: { type: MSG_COMPLETED }, origin: "https://evil.example" }));
    expect(onSuccess).not.toHaveBeenCalled();

    postFromCheckout({ type: MSG_COMPLETED });
    expect(onSuccess).toHaveBeenCalledTimes(1);

    // The paid close is still reported as completed.
    postFromCheckout({ type: MSG_CLOSED, completed: false });
    expect(onClose).toHaveBeenCalledWith(true);
  });

  it("reports an abandoned checkout as not completed", () => {
    const onClose = vi.fn();
    openPaddleCheckout({ priceId: "pri_test_2", onSuccess: vi.fn(), onClose });

    postFromCheckout({ type: MSG_CLOSED, completed: false });
    expect(onClose).toHaveBeenCalledWith(false);
  });

  it("drops stale callbacks after an abandoned checkout so a retry starts clean", () => {
    const firstClose = vi.fn();
    const secondSuccess = vi.fn();
    openPaddleCheckout({ priceId: "pri_test_a", onSuccess: vi.fn(), onClose: firstClose });
    postFromCheckout({ type: MSG_CLOSED, completed: false });
    expect(firstClose).toHaveBeenCalledWith(false);

    openPaddleCheckout({ priceId: "pri_test_b", onSuccess: secondSuccess, onClose: vi.fn() });
    postFromCheckout({ type: MSG_COMPLETED });
    expect(secondSuccess).toHaveBeenCalledTimes(1);
  });

  it("omits the email param when no email is provided", () => {
    openPaddleCheckout({ priceId: "pri_test_3", onSuccess: vi.fn(), onClose: vi.fn() });
    const src = new URL(document.querySelector<HTMLIFrameElement>("iframe")!.src);
    expect(src.searchParams.get("email")).toBeNull();
  });
});
