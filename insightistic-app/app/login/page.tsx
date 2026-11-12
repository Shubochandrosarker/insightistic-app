"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/auth/AuthShell";
import { OAuthButtons } from "@/components/auth/OAuthButtons";

/** Only same-origin relative redirects are allowed after authentication. */
function signedInDestination(isSuperAdmin: boolean): string {
  if (typeof window !== "undefined") {
    const next = new URLSearchParams(window.location.search).get("next");
    if (next && next.startsWith("/") && !next.startsWith("//") && !next.includes("\\")) {
      return next;
    }
  }

  return isSuperAdmin ? "/admin" : "/dashboard";
}

export default function Login() {
  const { login, completeMfa, user, loading } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  // Set once the password is accepted but a second factor is still needed.
  const [challenge, setChallenge] = useState<string | null>(null);
  const [code, setCode] = useState("");

  // Already signed in → send them where they belong.
  useEffect(() => {
    if (!loading && user) router.replace(signedInDestination(user.is_super_admin));
  }, [user, loading, router]);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (busy) return;
    setErr(null);
    setBusy(true);
    try {
      const result = await login(email.trim(), password);

      if (result.mfaRequired) {
        setChallenge(result.challenge);
        setBusy(false);
        return;
      }

      router.push(signedInDestination(Boolean(result.user?.is_super_admin)));
    } catch (e: any) {
      setErr(e.message || "Login failed");
      setBusy(false);
    }
  }

  async function submitCode(e: React.FormEvent) {
    e.preventDefault();
    if (busy || !challenge) return;
    setErr(null);
    setBusy(true);
    try {
      const u = await completeMfa(challenge, code.trim());
      router.push(signedInDestination(Boolean(u?.is_super_admin)));
    } catch (e: any) {
      setErr(e.message || "That code wasn't accepted.");
      setBusy(false);
      // An expired or burned challenge means starting the sign-in again.
      if (e?.data?.code === "challenge_expired") {
        setChallenge(null);
        setCode("");
        setPassword("");
      }
    }
  }

  // Second factor: the password was accepted, but no session exists yet.
  if (challenge)
    return (
      <AuthShell
        title="Two-factor authentication"
        subtitle="Enter the 6-digit code from your authenticator app."
        footer={
          <button
            type="button"
            onClick={() => { setChallenge(null); setCode(""); setErr(null); }}
            className="font-semibold text-brand hover:text-brand2"
          >
            Back to sign in
          </button>
        }
      >
        <form onSubmit={submitCode} noValidate>
          {err && (
            <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
              {err}
            </p>
          )}

          <label className="ins-label" htmlFor="code">Authentication code</label>
          <input
            id="code"
            className="ins-input"
            placeholder="123456"
            inputMode="numeric"
            autoComplete="one-time-code"
            autoFocus
            value={code}
            onChange={(e) => setCode(e.target.value)}
            required
          />

          <p className="mt-2 text-xs text-slate-500">
            Lost your device? Enter one of your recovery codes instead — each works once.
          </p>

          <button type="submit" disabled={busy || code.trim().length < 6} className="ins-btn mt-6">
            {busy ? "Verifying…" : "Verify →"}
          </button>
        </form>
      </AuthShell>
    );

  return (
    <AuthShell
      title="Welcome back"
      subtitle="Sign in to your Insightistic dashboard."
      footer={
        <>
          New to Insightistic?{" "}
          <Link href="/register" className="font-semibold text-brand hover:text-brand2">
            Create an account
          </Link>
        </>
      }
    >
      <OAuthButtons action="Sign in" />

      <form onSubmit={submit} noValidate>
        {err && (
          <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
            {err}
          </p>
        )}

        <label className="ins-label" htmlFor="email">Email</label>
        <input
          id="email"
          className="ins-input"
          placeholder="you@store.com"
          type="email"
          autoComplete="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />

        <div className="mt-4">
          <label className="ins-label" htmlFor="password">Password</label>
          <input
            id="password"
            className="ins-input"
            placeholder="••••••••"
            type="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </div>

        <div className="mt-4 flex items-center justify-between text-sm">
          <label className="flex items-center gap-2 text-slate-500">
            <input type="checkbox" defaultChecked className="accent-[var(--ins-brand)]" />
            Remember me
          </label>
          <Link href="/forgot-password" className="font-semibold text-brand hover:text-brand2">
            Forgot password?
          </Link>
        </div>

        <button type="submit" disabled={busy} className="ins-btn mt-6">
          {busy ? "Signing in…" : "Sign in →"}
        </button>
      </form>
    </AuthShell>
  );
}
