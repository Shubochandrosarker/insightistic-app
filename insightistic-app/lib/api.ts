const BASE = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000";

export function getToken(): string | null {
  return typeof window !== "undefined" ? localStorage.getItem("ins_token") : null;
}
export function setToken(t: string | null) {
  if (typeof window === "undefined") return;
  if (t) localStorage.setItem("ins_token", t);
  else localStorage.removeItem("ins_token");
}

export interface ApiError { status: number; message: string; data?: any; }

async function request(path: string, opts: RequestInit = {}, json = true) {
  const token = getToken();
  const headers: Record<string, string> = { Accept: "application/json", ...(opts.headers as any) };
  if (token) headers["Authorization"] = `Bearer ${token}`;
  if (json && opts.body) headers["Content-Type"] = "application/json";

  const res = await fetch(`${BASE}/api${path}`, { ...opts, headers });
  const data = await res.json().catch(() => null);
  if (!res.ok) {
    throw { status: res.status, message: data?.message || `HTTP ${res.status}`, data } as ApiError;
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
