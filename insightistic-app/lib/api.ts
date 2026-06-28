/**
 * Insightistic API client.
 *
 * BASE resolution:
 *  - If NEXT_PUBLIC_API_URL is set  -> direct mode (browser calls that origin).
 *  - If it is empty (recommended)   -> same-origin proxy mode: the browser
 *    calls "/api/..." on the app's own host and Next.js forwards it to Laravel
 *    (see next.config.mjs). No CORS, no mixed-content, no "Failed to fetch".
 */
const BASE = (process.env.NEXT_PUBLIC_API_URL || "").replace(/\/$/, "");

export function getToken(): string | null {
  return typeof window !== "undefined" ? localStorage.getItem("ins_token") : null;
}
export function setToken(t: string | null) {
  if (typeof window === "undefined") return;
  if (t) localStorage.setItem("ins_token", t);
  else localStorage.removeItem("ins_token");
}

export interface ApiError {
  status: number;
  message: string;
  errors?: Record<string, string[]>;
  data?: any;
  network?: boolean;
}

/** Flatten Laravel 422 validation bags into one readable line. */
function firstValidationMessage(data: any): string | null {
  if (data?.errors && typeof data.errors === "object") {
    const first = Object.values(data.errors)[0];
    if (Array.isArray(first) && first.length) return String(first[0]);
  }
  return null;
}

async function request(path: string, opts: RequestInit = {}, json = true) {
  const token = getToken();
  const headers: Record<string, string> = { Accept: "application/json", ...(opts.headers as any) };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  if (json && opts.body) headers["Content-Type"] = "application/json";

  let res: Response;
  try {
    res = await fetch(`${BASE}/api${path}`, { ...opts, headers });
  } catch (e) {
    // fetch() only rejects on network-level failures: DNS, refused connection,
    // blocked CORS preflight, mixed content, TLS errors. Surface a human message
    // instead of the raw "Failed to fetch".
    throw {
      status: 0,
      network: true,
      message:
        "Can't reach the Insightistic server. Please check your connection and try again in a moment.",
    } as ApiError;
  }

  const data = await res.json().catch(() => null);

  if (!res.ok) {
    throw {
      status: res.status,
      message: firstValidationMessage(data) || data?.message || `Request failed (HTTP ${res.status})`,
      errors: data?.errors,
      data,
    } as ApiError;
  }
  return data;
}

export const apiGet = (p: string) => request(p);
export const apiPost = (p: string, body?: any) =>
  request(p, { method: "POST", body: body ? JSON.stringify(body) : undefined });
export const apiPatch = (p: string, body?: any) =>
  request(p, { method: "PATCH", body: JSON.stringify(body) });
export const apiDelete = (p: string) => request(p, { method: "DELETE" });
export const apiUpload = (p: string, form: FormData) =>
  request(p, { method: "POST", body: form }, false);
