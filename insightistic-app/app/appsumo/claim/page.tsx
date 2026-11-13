"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/auth/AuthShell";
import { apiGet, apiPost, getToken, type ApiError } from "@/lib/api";

const PLAN_LABELS: Record<string, string> = {
  appsumo_tier_1: "AppSumo Tier 1 — lifetime",
  appsumo_tier_2: "AppSumo Tier 2 — lifetime",
  appsumo_tier_3: "AppSumo Tier 3 — lifetime",
  appsumo_tier_4: "AppSumo Tier 4 — lifetime",
  appsumo_tier_5: "AppSumo Tier 5 — lifetime",
  appsumo_solo: "AppSumo legacy tier 1 — lifetime",
  appsumo_studio: "AppSumo legacy tier 2 — lifetime",
  appsumo_agency: "AppSumo legacy tier 3 — lifetime",
};

interface Precheck {
  status: string;
  tier: number | null;
  plan_slug: string | null;
  linked: boolean;
}

/**
 * AppSumo buyer claim page — the SPA half of the OAuth loop. The Laravel
 * AppSumoOAuthController redirects here with ?license=<key> after upserting
 * the license row; this page pre-checks the key, collects the buyer's email +
 * password, and POSTs /appsumo/claim which returns a Sanctum token.
 */
export default function AppSumoClaim() {
  const { applyToken, user, loading } = useAuth();
  const router = useRouter();
  const [licenseKey, setLicenseKey] = useState<string | null>(null);
  const [pre, setPre] = useState<Precheck | null>(null);
  const [checking, setChecking] = useState(true);
  const [fatal, setFatal] = useState<string | null>(null);
  const [f, setF] = useState({ name: "", email: "", password: "" });
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const up = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setF({ ...f, [k]: e.target.value });

  useEffect(() => {
    // Same window.location.search pattern as /register — reads the query
    // without forcing a Suspense boundary around useSearchParams.
    const key = new URLSearchParams(window.location.search).get("license");
    if (!key) {
      setFatal(
        "This claim link is missing its license reference. Please open the exact link you received after your AppSumo purchase.",
      );
      setChecking(false);
      return;
    }
    setLicenseKey(key);
    apiGet(`/appsumo/claim/${encodeURIComponent(key)}`)
      .then((p: Precheck) => {
        setPre(p);
        if (p.status !== "active") {
          setFatal(
            "This AppSumo license is not active. Contact AppSumo support if you believe this is a mistake.",
          );
        }
      })
      .catch((e: ApiError) => {
        setFatal(
          e.status === 404
            ? "We could not find that AppSumo license. Please double-check the link from your purchase confirmation."
            : e.message,
        );
      })
      .finally(() => setChecking(false));
  }, []);

  async function claimSignedIn() {
    if (!licenseKey || busy) return;
    setBusy(true);
    setErr(null);
    try {
      await apiPost("/appsumo/claim/authenticated", { license_key: licenseKey });
      const token = getToken();
      if (token) await applyToken(token);
      router.push("/dashboard");
    } catch (e: any) {
      setErr(e.message || "We could not activate this license. Please retry.");
      setBusy(false);
    }
  }

  function validate(): string | null {
    if (!f.email.trim()) return "Please enter the email for your account.";
    if (!/^\S+@\S+\.\S+$/.test(f.email.trim())) return "Please enter a valid email.";
    if (f.password.length < 8) return "Password must be at least 8 characters.";
    return null;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (busy || !licenseKey) return;
    const v = validate();
    if (v) return setErr(v);
    setErr(null);
    setBusy(true);
    try {
      const res = await apiPost("/appsumo/claim", {
        license_key: licenseKey,
        email: f.email.trim(),
        password: f.password,
        name: f.name.trim() || undefined,
      });
      // Same token adoption as the OAuth callback: store it, then hydrate
      // user + organizations from /auth/me before landing on the dashboard.
      await applyToken(res.token);
      router.push("/dashboard");
    } catch (e: any) {
      setErr(e.message || "We could not complete the claim right now. Please try again.");
      setBusy(false);
    }
  }

  return (
    <AuthShell
      title="Activate your AppSumo license"
      subtitle={
        pre?.linked
          ? "This license is already active — sign in below with the account that claimed it."
          : "One last step: create your workspace (or sign in to an existing one) and your lifetime plan is applied automatically."
      }
      footer={
        <>
          Already have an account?{" "}
          <Link href={licenseKey ? `/login?next=${encodeURIComponent(`/appsumo/claim?license=${licenseKey}`)}` : "/login"} className="font-semibold text-brand hover:text-brand2">
            Sign in
          </Link>
        </>
      }
    >
      {pre?.plan_slug && PLAN_LABELS[pre.plan_slug] && (
        <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-brand/30 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand-700">
          <span className="ins-logo-dot" /> {PLAN_LABELS[pre.plan_slug]}
        </div>
      )}

      {checking && <p className="text-sm text-slate-500">Checking your license…</p>}

      {fatal && (
        <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
          {fatal}
        </p>
      )}

      {!checking && !fatal && licenseKey && !loading && user && (
        <div>
          <p className="mb-3 text-sm text-slate-600">
            You are signed in as <strong>{user.email}</strong>. Activate this lifetime license on your owned workspace.
          </p>
          {err && (
            <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">{err}</p>
          )}
          <button type="button" className="ins-btn mt-3" disabled={busy} onClick={claimSignedIn}>
            {busy ? "Activating…" : "Activate on my account"}
          </button>
        </div>
      )}

      {!checking && !fatal && licenseKey && !loading && !user && (
        <form onSubmit={submit} noValidate>
          {err && (
            <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
              {err}
            </p>
          )}

          <label className="ins-label" htmlFor="email">Email</label>
          <input id="email" className="ins-input" placeholder="you@agency.com" type="email"
            autoComplete="email" value={f.email} onChange={up("email")} required />

          <div className="mt-4">
            <label className="ins-label" htmlFor="password">Password</label>
            <input id="password" className="ins-input" placeholder="8+ characters" type="password"
              autoComplete={pre?.linked ? "current-password" : "new-password"}
              value={f.password} onChange={up("password")} required />
          </div>

          {!pre?.linked && (
            <div className="mt-4">
              <label className="ins-label" htmlFor="name">Full name</label>
              <input id="name" className="ins-input" placeholder="Shuvo Sarkar"
                autoComplete="name" value={f.name} onChange={up("name")} />
              <p className="mt-1 text-xs text-slate-500">
                Used only if we create a new account for this email.
              </p>
            </div>
          )}

          <button type="submit" disabled={busy} className="ins-btn mt-6">
            {busy ? "Activating…" : pre?.linked ? "Sign in & continue →" : "Activate my license →"}
          </button>
        </form>
      )}
    </AuthShell>
  );
}
