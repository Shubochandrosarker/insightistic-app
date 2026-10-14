import { describe, it, expect, vi, beforeEach } from "vitest";

const sdkMocks = vi.hoisted(() => ({
  initializePaddle: vi.fn(),
}));

vi.mock("@paddle/paddle-js", () => sdkMocks);

import {
  initPaddle,
  openPaddleCheckout,
  resetPaddleForTests,
} from "@/lib/paddle";

function fakePaddle() {
  return {
    Checkout: {
      open: vi.fn(),
      close: vi.fn(),
    },
    Environment: { set: vi.fn() },
    Initialized: true,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  resetPaddleForTests();
  sdkMocks.initializePaddle.mockResolvedValue(fakePaddle());
});

describe("paddle lib", () => {
  it("initializes the supported Paddle SDK exactly once for a token/environment", async () => {
    const first = initPaddle("tok_once", "production");
    const second = initPaddle("tok_once", "production");

    const [a, b] = await Promise.all([first, second]);

    expect(a).toBe(b);
    expect(sdkMocks.initializePaddle).toHaveBeenCalledTimes(1);
    expect(sdkMocks.initializePaddle).toHaveBeenCalledWith({
      token: "tok_once",
      environment: "production",
      eventCallback: expect.any(Function),
    });
  });

  it("passes sandbox environment selection to the SDK", async () => {
    await initPaddle("tok_sandbox", "sandbox");

    expect(sdkMocks.initializePaddle).toHaveBeenCalledWith({
      token: "tok_sandbox",
      environment: "sandbox",
      eventCallback: expect.any(Function),
    });
  });

  it("rejects empty runtime tokens", async () => {
    await expect(initPaddle("  ", "production")).rejects.toThrow(
      "Paddle checkout is not configured yet.",
    );
    expect(sdkMocks.initializePaddle).not.toHaveBeenCalled();
  });

  it("opens an API-issued one-page overlay and routes SDK lifecycle events", async () => {
    const paddle = fakePaddle();
    sdkMocks.initializePaddle.mockResolvedValue(paddle);
    await initPaddle("tok_checkout", "production");

    const onSuccess = vi.fn();
    const onClose = vi.fn();
    openPaddleCheckout({
      priceId: "pri_test_growth",
      email: "owner@example.com",
      customData: { organization_id: 7, plan_slug: "growth" },
      onSuccess,
      onClose,
    });

    expect(paddle.Checkout.open).toHaveBeenCalledWith({
      items: [{ priceId: "pri_test_growth", quantity: 1 }],
      customer: { email: "owner@example.com" },
      customData: { organization_id: 7, plan_slug: "growth" },
      settings: {
        displayMode: "overlay",
        variant: "one-page",
        successUrl: `${window.location.origin}/dashboard/billing?checkout=success`,
      },
    });

    const eventCallback = sdkMocks.initializePaddle.mock.calls[0][0].eventCallback;
    eventCallback({ name: "checkout.completed" });
    eventCallback({ name: "checkout.closed" });

    expect(onSuccess).toHaveBeenCalledTimes(1);
    expect(onClose).toHaveBeenCalledWith(true);
  });

  it("reports an abandoned checkout as not completed", async () => {
    const paddle = fakePaddle();
    sdkMocks.initializePaddle.mockResolvedValue(paddle);
    await initPaddle("tok_abandoned", "production");

    const onClose = vi.fn();
    openPaddleCheckout({ priceId: "pri_test_2", onSuccess: vi.fn(), onClose });

    const eventCallback = sdkMocks.initializePaddle.mock.calls[0][0].eventCallback;
    eventCallback({ name: "checkout.closed" });

    expect(onClose).toHaveBeenCalledWith(false);
  });

  it("omits the customer when no email is provided", async () => {
    const paddle = fakePaddle();
    sdkMocks.initializePaddle.mockResolvedValue(paddle);
    await initPaddle("tok_no_customer", "production");

    openPaddleCheckout({ priceId: "pri_test_3", onSuccess: vi.fn(), onClose: vi.fn() });

    expect(paddle.Checkout.open).toHaveBeenCalledWith(
      expect.not.objectContaining({ customer: expect.anything() }),
    );
  });
});
