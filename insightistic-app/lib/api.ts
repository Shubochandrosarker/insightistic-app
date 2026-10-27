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

export interface ImpersonationMeta {
  session_id: number;
  admin_name: string;
  target_name: string;
  reason: string;
  elevated: boolean;
  started_at: string;
}

/**
 * Impersonation (spec §13.9) stashes the admin's own token + a copy of the
 * new impersonation token here before switching the active session
 * (lib/auth.tsx's applyToken) to the target user — "clear exit" restores
 * from this stash rather than requiring the admin to log in again.
 */
export function getImpersonation(): { adminToken: string; meta: ImpersonationMeta } | null {
  if (typeof window === "undefined") return null;
  const adminToken = localStorage.getItem("ins_admin_return_token");
  const metaRaw = localStorage.getItem("ins_impersonation_meta");
  if (!adminToken || !metaRaw) return null;
  try {
    return { adminToken, meta: JSON.parse(metaRaw) };
  } catch {
    return null;
  }
}
export function setImpersonation(adminToken: string, meta: ImpersonationMeta) {
  if (typeof window === "undefined") return;
  localStorage.setItem("ins_admin_return_token", adminToken);
  localStorage.setItem("ins_impersonation_meta", JSON.stringify(meta));
}
export function clearImpersonation() {
  if (typeof window === "undefined") return;
  localStorage.removeItem("ins_admin_return_token");
  localStorage.removeItem("ins_impersonation_meta");
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

const RETRYABLE_GET_STATUSES = new Set([408, 425, 500, 502, 503, 504, 520, 521, 522, 523, 524]);

const pause = (milliseconds: number) =>
  new Promise<void>((resolve) => window.setTimeout(resolve, milliseconds));

async function request(path: string, opts: RequestInit = {}, json = true) {
  const token = getToken();
  const headers: Record<string, string> = { Accept: "application/json", ...(opts.headers as any) };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  if (json && opts.body) headers["Content-Type"] = "application/json";

  // Dashboard reads are safe to retry. A short bounded retry absorbs the
  // occasional Cloudflare/origin 52x or dropped connection without replaying
  // mutations such as checkout, login, or settings updates.
  const method = (opts.method || "GET").toUpperCase();
  const maxAttempts = method === "GET" ? 3 : 1;

  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    let res: Response;
    try {
      res = await fetch(`${BASE}/api${path}`, { ...opts, headers });
    } catch {
      if (attempt + 1 < maxAttempts) {
        await pause(250 * Math.pow(2, attempt));
        continue;
      }
      // fetch() only rejects on network-level failures: DNS, refused
      // connections, blocked CORS, mixed content, or TLS errors.
      throw {
        status: 0,
        network: true,
        message:
          "Can\'t reach the Insightistic server. Please check your connection and try again in a moment.",
      } as ApiError;
    }

    const data = await res.json().catch(() => null);

    if (!res.ok) {
      if (
        attempt + 1 < maxAttempts &&
        RETRYABLE_GET_STATUSES.has(res.status)
      ) {
        await pause(250 * Math.pow(2, attempt));
        continue;
      }
      throw {
        status: res.status,
        message: firstValidationMessage(data) || data?.message || `Request failed (HTTP ${res.status})`,
        errors: data?.errors,
        data,
      } as ApiError;
    }

    return data;
  }

  // The loop either returns or throws. This keeps TypeScript exhaustive if
  // maxAttempts is changed to a computed value later.
  throw { status: 0, network: true, message: "Request failed." } as ApiError;
}
export const apiGet = (p: string) => request(p);
export const apiPost = (p: string, body?: any) =>
  request(p, { method: "POST", body: body ? JSON.stringify(body) : undefined });
export const apiPatch = (p: string, body?: any) =>
  request(p, { method: "PATCH", body: JSON.stringify(body) });
export const apiDelete = (p: string) => request(p, { method: "DELETE" });
export const apiUpload = (p: string, form: FormData) =>
  request(p, { method: "POST", body: form }, false);

/** Downloads a binary/CSV endpoint (needs the Bearer header, unlike a plain <a href>). */
export async function apiDownload(p: string, filename: string) {
  const token = getToken();
  const headers: Record<string, string> = {};
  if (token) headers["Authorization"] = `Bearer ${token}`;

  const res = await fetch(`${BASE}/api${p}`, { headers });
  if (!res.ok) {
    const data = await res.json().catch(() => null);
    throw { status: res.status, message: data?.message || `Export failed (HTTP ${res.status})` } as ApiError;
  }

  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}
