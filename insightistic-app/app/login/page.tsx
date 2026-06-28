"use client";
import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/auth/AuthShell";
import { OAuthButtons } from "@/components/auth/OAuthButtons";

export default function Login() {
  const { login } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (busy) return;
    setErr(null);
    setBusy(true);
    try {
      await login(email.trim(), password);
      router.push("/dashboard");
    } catch (e: any) {
      setErr(e.message || "Login failed");
      setBusy(false);
    }
  }

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
