import {
  initializePaddle,
  type Paddle,
  type PaddleEventData,
} from "@paddle/paddle-js";

export type PaddleEnvironment = "sandbox" | "production";

export interface PaddleCheckoutItem {
  priceId: string;
  quantity: number;
}

export interface PaddleCheckoutOpenOptions {
  items: PaddleCheckoutItem[];
  customer?: { email: string };
  customData?: Record<string, unknown>;
  settings?: {
    displayMode?: "overlay" | "inline";
    successUrl?: string;
    variant?: "one-page" | "multi-page";
    [key: string]: unknown;
  };
}

export type PaddleJs = Paddle;

let initializedKey: string | null = null;
let paddleInstance: Paddle | null = null;
let initPromise: Promise<Paddle> | null = null;
let activeCallbacks: {
  onSuccess: () => void;
  onClose: (completed: boolean) => void;
} | null = null;
let checkoutCompleted = false;

function handleCheckoutEvent(event: PaddleEventData): void {
  if (event.name === "checkout.completed") {
    checkoutCompleted = true;
    activeCallbacks?.onSuccess();
    return;
  }

  if (event.name === "checkout.closed") {
    const callbacks = activeCallbacks;
    activeCallbacks = null;
    callbacks?.onClose(checkoutCompleted);
  }
}

/**
 * Initialize Paddle.js through the supported SDK. The token is issued by the
 * API at runtime; no billing credential is bundled into the Next.js build.
 * The SDK owns the CDN loader and the event callback owns checkout lifecycle.
 */
export async function initPaddle(
  token: string,
  environment: PaddleEnvironment,
): Promise<Paddle> {
  const normalizedToken = token.trim();
  if (!normalizedToken) {
    throw new Error("Paddle checkout is not configured yet.");
  }

  const key = `${environment}:${normalizedToken}`;
  if (initializedKey && initializedKey !== key) {
    throw new Error("Paddle is already initialized with another checkout environment.");
  }
  if (initPromise) return initPromise;

  initializedKey = key;
  initPromise = initializePaddle({
    token: normalizedToken,
    environment,
    eventCallback: handleCheckoutEvent,
  }).then((paddle) => {
    if (!paddle) {
      throw new Error("Paddle checkout could not be initialized.");
    }
    paddleInstance = paddle;
    return paddle;
  }).catch((error) => {
    initializedKey = null;
    initPromise = null;
    paddleInstance = null;
    throw error;
  });

  return initPromise;
}

export interface OpenPaddleCheckoutOptions {
  priceId: string;
  email?: string;
  customData?: Record<string, unknown>;
  onSuccess: () => void;
  onClose: (completed: boolean) => void;
}

/** Open an overlay checkout for one API-issued price. */
export function openPaddleCheckout(opts: OpenPaddleCheckoutOptions): void {
  if (!paddleInstance) {
    throw new Error("Paddle.js is not initialized yet. Call initPaddle first.");
  }

  checkoutCompleted = false;
  activeCallbacks = { onSuccess: opts.onSuccess, onClose: opts.onClose };

  paddleInstance.Checkout.open({
    items: [{ priceId: opts.priceId, quantity: 1 }],
    ...(opts.email ? { customer: { email: opts.email } } : {}),
    customData: opts.customData,
    settings: {
      displayMode: "overlay",
      variant: "one-page",
      successUrl: `${window.location.origin}/dashboard/billing?checkout=success`,
    },
  });
}

/** Test-only reset hook; never called by production code. */
export function resetPaddleForTests(): void {
  initializedKey = null;
  paddleInstance = null;
  initPromise = null;
  activeCallbacks = null;
  checkoutCompleted = false;
}
