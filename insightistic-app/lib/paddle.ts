/**
 * Paddle.js v2 loader (roadmap 22A §9).
 *
 * One module owns the script tag and the Initialize call — components never
 * touch `window.Paddle` directly. The client token arrives from the API at
 * runtime (`/billing/checkout-config`); nothing here is a secret and nothing
 * is baked in at build time.
 *
 * CHECKOUT DOMAIN (fix/billing-pay-domain, 2026-10-13): Paddle only creates
 * transactions for checkouts opened on the vendor's APPROVED domain —
 * pay.insightistic.com. `Checkout.open()` from app.insightistic.com fails
 * with 403 "Transaction checkout creation is blocked for this vendor" (the
 * founder-visible billing error). So the overlay now runs INSIDE an iframe
 * hosted on pay.insightistic.com; this module talks to it via postMessage:
 *   dashboard → iframe : { type: "insightistic-checkout-open", …config }
 *   iframe → dashboard : { type: "insightistic-checkout-completed" |
 *                          "insightistic-checkout-closed", completed }
 *
 * Paddle.js API note: current Paddle.js v2 builds (v2.10 verified live
 * 2026-10-13) expose `Checkout.{open,close,updateCheckout,updateItems}`
 * only — `Checkout.on()` is GONE, so completion events arrive via the
 * iframe bridge, not `Paddle.Checkout.on("checkout.completed")` (that call
 * threw "t.on is not a function" in production).
 */

export type PaddleEnvironment = "sandbox" | "production";

/** Hosted checkout shell on the Paddle-approved domain (pay host serves it). */
export const CHECKOUT_HOST_URL = (
  process.env.NEXT_PUBLIC_PADDLE_CHECKOUT_URL ?? "https://pay.insightistic.com"
).replace(/\/$/, "");

/** postMessage types crossing the pay-host iframe boundary. */
export const MSG_OPEN = "insightistic-checkout-open";
export const MSG_COMPLETED = "insightistic-checkout-completed";
export const MSG_CLOSED = "insightistic-checkout-closed";

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
    [key: string]: unknown;
  };
}

export interface PaddleInitializeOptions {
  token: string;
}

/** The slice of Paddle.js v2 this app uses (v2.10 surface). */
export interface PaddleJs {
  Environment: { set(environment: string): void };
  Initialize(options: PaddleInitializeOptions): void;
  Checkout: {
    open(options: PaddleCheckoutOpenOptions): void;
  };
}

declare global {
  interface Window {
    Paddle?: PaddleJs;
  }
}

const SCRIPT_ID = "paddle-js";
const SCRIPT_SRC = "https://cdn.paddle.com/paddle/v2/paddle.js";
const LOAD_TIMEOUT_MS = 10_000;

let loaderPromise: Promise<void> | null = null;

/** Inject the CDN script exactly once; resolve on load, reject on error/timeout. */
function loadPaddleJs(): Promise<void> {
  if (loaderPromise) return loaderPromise;
  loaderPromise = new Promise<void>((resolve, reject) => {
    const existing = document.getElementById(SCRIPT_ID) as HTMLScriptElement | null;
    const script = existing ?? document.createElement("script");
    if (!existing) {
      script.id = SCRIPT_ID;
      script.src = SCRIPT_SRC;
      script.async = true;
      document.head.appendChild(script);
    }

    const timer = setTimeout(() => {
      loaderPromise = null; // a timeout must be retryable
      reject(new Error("Paddle.js took too long to load. Check your connection and try again."));
    }, LOAD_TIMEOUT_MS);

    script.addEventListener("load", () => {
      clearTimeout(timer);
      resolve();
    });
    script.addEventListener("error", () => {
      clearTimeout(timer);
      loaderPromise = null;
      reject(new Error("Paddle.js failed to load. Check your connection and try again."));
    });
  });
  return loaderPromise;
}

/**
 * Sandbox gate + Initialize, factored out of initPaddle so tests can assert
 * call order against a fake Paddle global without loading the CDN script.
 * Sandbox is not Paddle.js's default, so it must be selected before Initialize.
 */
export function initializeWith(P: PaddleJs, token: string, environment: PaddleEnvironment): PaddleJs {
  if (environment === "sandbox") P.Environment.set("sandbox");
  // Initialize takes ONLY the token. `environment` is not an Initialize
  // option in any Paddle.js v2 build — passing it throws
  // "[PADDLE] Unknown option parameter 'environment'" and every checkout
  // dies before opening. Sandbox selection above is the whole mechanism.
  P.Initialize({ token });
  return P;
}

const initPromises = new Map<string, Promise<PaddleJs>>();

/** Payload for one checkout open request, forwarded into the pay-host iframe. */
export interface OpenPaddleCheckoutOptions {
  priceId: string;
  email?: string;
  customData?: Record<string, unknown>;
  /** The checkout completed (paid). */
  onSuccess: () => void;
  /** The checkout UI closed; `completed` says whether payment finished. */
  onClose: (completed: boolean) => void;
  /** Called when the overlay could not start at all (bridge failure). */
  onUnavailable?: () => void;
}

let messageHandler: ((e: MessageEvent) => void) | null = null;
let activeCallbacks: { onSuccess: () => void; onClose: (completed: boolean) => void; paid: boolean } | null = null;

/** True when the message comes from the pay-host checkout iframe origin. */
function isCheckoutOrigin(origin: string): boolean {
  try {
    return new URL(CHECKOUT_HOST_URL).origin === origin;
  } catch {
    return false;
  }
}

/**
 * Route iframe messages to whoever opened the latest checkout. One listener
 * for the whole module — retries never stack duplicate handlers (the same
 * discipline the old Checkout.on wrapper enforced).
 */
function ensureMessageListener(): void {
  if (messageHandler) return;
  messageHandler = (e: MessageEvent) => {
    if (!isCheckoutOrigin(e.origin)) return;
    const type = (e.data as { type?: string } | null)?.type;
    if (type === MSG_COMPLETED) {
      if (activeCallbacks) activeCallbacks.paid = true;
      activeCallbacks?.onSuccess();
    } else if (type === MSG_CLOSED) {
      const completed = activeCallbacks?.paid || !!(e.data as { completed?: boolean } | null)?.completed;
      activeCallbacks?.onClose(completed);
      if (!completed) activeCallbacks = null; // abandoned: allow a fresh open
    }
  };
  window.addEventListener("message", messageHandler);
}

export function teardownMessageListenerForTest(): void {
  if (messageHandler) window.removeEventListener("message", messageHandler);
  messageHandler = null;
  activeCallbacks = null;
}

/**
 * Open a checkout on the APPROVED pay domain, rendered as an in-page overlay
 * (fullscreen-hosted iframe) so the buyer never leaves the dashboard.
 *
 * Everything about the transaction (price, email, org routing hint) is
 * forwarded to the hosted checkout page, which runs Paddle.js itself — the
 * transaction is created on pay.insightistic.com, so Paddle accepts it.
 * Completion comes back over postMessage; a paid close wins over an
 * abandoned one (mirrors the old paidRef semantics).
 */
export function openPaddleCheckout(opts: OpenPaddleCheckoutOptions): void {
  activeCallbacks = { onSuccess: opts.onSuccess, onClose: opts.onClose, paid: false };
  ensureMessageListener();

  const params = new URLSearchParams({ price: opts.priceId, embed: "1" });
  if (opts.email) params.set("email", opts.email);
  if (opts.customData) params.set("data", JSON.stringify(opts.customData));

  const wrap = document.createElement("div");
  wrap.id = "insightistic-checkout-frame";
  Object.assign(wrap.style, {
    position: "fixed",
    inset: "0",
    zIndex: "2147483647",
    background: "rgba(2,6,23,0.6)",
  } as CSSStyleDeclaration);

  const frame = document.createElement("iframe");
  frame.src = `${CHECKOUT_HOST_URL}/?${params.toString()}`;
  frame.allow = "payment *";
  frame.title = "Secure checkout — Insightistic";
  Object.assign(frame.style, {
    position: "absolute",
    inset: "0",
    width: "100%",
    height: "100%",
    border: "0",
  } as CSSStyleDeclaration);

  // Dashboard-side escape hatch: the iframe covers the whole app, so a close
  // control must live ABOVE it (an abandoned checkout posts a closed event
  // too — this is the immediate manual path).
  const closeBtn = document.createElement("button");
  closeBtn.type = "button";
  closeBtn.setAttribute("aria-label", "Close checkout");
  closeBtn.textContent = "✕";
  Object.assign(closeBtn.style, {
    position: "absolute",
    top: "12px",
    right: "12px",
    zIndex: "1",
    width: "36px",
    height: "36px",
    borderRadius: "9999px",
    border: "0",
    fontSize: "16px",
    lineHeight: "1",
    cursor: "pointer",
    color: "#0b1220",
    background: "rgba(255,255,255,0.92)",
    boxShadow: "0 2px 10px rgba(0,0,0,0.35)",
  } as CSSStyleDeclaration);

  const removeOverlay = () => wrap.remove();
  closeBtn.addEventListener("click", () => {
    const paid = activeCallbacks?.paid ?? false;
    removeOverlay();
    activeCallbacks?.onClose(paid);
    if (!paid) activeCallbacks = null;
  });

  wrap.appendChild(frame);
  wrap.appendChild(closeBtn);
  document.body.appendChild(wrap);

  // If the hosted page never comes up (pay host down, network broken), fail
  // visibly instead of leaving a blank overlay forever.
  window.setTimeout(() => {
    if (wrap.isConnected && frame.dataset.loaded !== "1") {
      removeOverlay();
      activeCallbacks = null;
      opts.onUnavailable?.();
    }
  }, 15_000);
  frame.addEventListener("load", () => {
    frame.dataset.loaded = "1";
  });
}

/**
 * Load Paddle.js and Initialize it with the given client token. Cached by
 * token+environment: repeated calls never re-run Initialize (Paddle throws
 * on double initialization), and a failed attempt is retryable.
 */
export async function initPaddle(token: string, environment: PaddleEnvironment): Promise<PaddleJs> {
  const cacheKey = `${environment}:${token}`;
  const cached = initPromises.get(cacheKey);
  if (cached) return cached;

  const init = (async () => {
    await loadPaddleJs();
    const P = window.Paddle;
    if (!P) throw new Error("Paddle.js loaded but the Paddle global is unavailable.");
    return initializeWith(P, token, environment);
  })();

  initPromises.set(cacheKey, init);
  init.catch(() => initPromises.delete(cacheKey));
  return init;
}

