/**
 * Paddle.js v2 loader (roadmap 22A §9).
 *
 * One module owns the script tag and the Initialize call — components never
 * touch `window.Paddle` directly. The client token arrives from the API at
 * runtime (`/billing/checkout-config`); nothing here is a secret and nothing
 * is baked in at build time.
 */

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
    [key: string]: unknown;
  };
}

export interface PaddleInitializeOptions {
  token: string;
}

/** The slice of Paddle.js v2 this app uses. */
export interface PaddleJs {
  Environment: { set(environment: string): void };
  Initialize(options: PaddleInitializeOptions): void;
  Checkout: {
    open(options: PaddleCheckoutOpenOptions): void;
    on(event: string, callback: (eventData?: unknown) => void): void;
    /** Present on current builds; turns checkout.closed into "paid vs abandoned". */
    isCompleted?(): boolean;
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

// Paddle.js's `Checkout.on` registers a persistent listener, so the events
// are bound once and routed to whoever opened the latest checkout — otherwise
// every retry would stack another copy of the same handlers.
let boundCheckout: PaddleJs["Checkout"] | null = null;
let activeCallbacks: { onSuccess: () => void; onClose: (completed: boolean) => void } | null = null;

function bindCheckoutEvents(checkout: PaddleJs["Checkout"]) {
  if (boundCheckout === checkout) return;
  boundCheckout = checkout;
  checkout.on("checkout.completed", () => activeCallbacks?.onSuccess());
  checkout.on("checkout.closed", () => {
    let completed = false;
    try {
      completed = typeof checkout.isCompleted === "function" ? !!checkout.isCompleted() : false;
    } catch {
      completed = false;
    }
    activeCallbacks?.onClose(completed);
  });
}

export interface OpenPaddleCheckoutOptions {
  priceId: string;
  email?: string;
  customData?: Record<string, unknown>;
  onSuccess: () => void;
  onClose: (completed: boolean) => void;
}

/** Open an overlay checkout for one price. */
export function openPaddleCheckout(opts: OpenPaddleCheckoutOptions): void {
  const P = window.Paddle;
  if (!P) throw new Error("Paddle.js is not initialized yet. Call initPaddle first.");

  activeCallbacks = { onSuccess: opts.onSuccess, onClose: opts.onClose };
  bindCheckoutEvents(P.Checkout);

  P.Checkout.open({
    items: [{ priceId: opts.priceId, quantity: 1 }],
    customer: opts.email ? { email: opts.email } : undefined,
    customData: opts.customData,
    settings: {
      displayMode: "overlay",
      successUrl: `${window.location.origin}/dashboard/billing?checkout=success`,
    },
  });
}
