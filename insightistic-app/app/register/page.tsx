"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/auth/AuthShell";
import { OAuthButtons } from "@/components/auth/OAuthButtons";

const PLAN_LABELS: Record<string, string> = {
  starter: "Starter",
  pro: "Pro",
  agency: "Agency",
};

export default function Register() {
  const { register, user, loading } = useAuth();
  const router = useRouter();
  const [f, setF] = useState({ name: "", email: "", password: "", organization_name: "" });
  const [plan, setPlan] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const up = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setF({ ...f, [k]: e.target.value });

  useEffect(() => {
    if (!loading && user) router.replace(user.is_super_admin ? "/admin" : "/dashboard");
  }, [user, loading, router]);

  // Read ?plan= from the marketing-site CTA without forcing a Suspense boundary.
  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get("plan");
    if (p && PLAN_LABELS[p]) setPlan(p);
  }, []);

  function validate(): string | null {
    if (!f.name.trim()) return "Please enter your full name.";
    if (!/^\S+@\S+\.\S+$/.test(f.email.trim())) return "Please enter a valid work email.";
    if (f.password.length < 8) return "Password must be at least 8 characters.";
    if (!f.organization_name.trim()) return "Please enter your organization name.";
    return null;
  }

  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (busy) return;
    const v = validate();
    if (v) return setErr(v);
    setErr(null);
    setBusy(true);
    try {
      await register({ ...f, name: f.name.trim(), email: f.email.trim() });
      router.push(plan ? `/dashboard/billing?plan=${plan}` : "/dashboard");
    } catch (e: any) {
      setErr(e.message || "Sign up failed");
      setBusy(false);
    }
  }

  return (
    <AuthShell
      title="Start your free trial"
      subtitle="14 days free. No credit card required."
      footer={
        <>
          Already have an account?{" "}
          <Link href="/login" className="font-semibold text-brand hover:text-brand2">
            Sign in
          </Link>
        </>
      }
    >
      {plan && (
        <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-brand/30 bg-brand/10 px-3 py-1 text-xs font-semibold text-brand-700">
          <span className="ins-logo-dot" /> {PLAN_LABELS[plan]} plan selected
        </div>
      )}

      <OAuthButtons action="Sign up" />

      <form onSubmit={submit} noValidate>
        {err && (
          <p className="mb-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
            {err}
          </p>
        )}

        <label className="ins-label" htmlFor="name">Full name</label>
        <input id="name" className="ins-input" placeholder="Shuvo Sarkar"
          autoComplete="name" value={f.name} onChange={up("name")} required />

        <div className="mt-4">
          <label className="ins-label" htmlFor="email">Work email</label>
          <input id="email" className="ins-input" placeholder="you@agency.com" type="email"
            autoComplete="email" value={f.email} onChange={up("email")} required />
        </div>

        <div className="mt-4">
          <label className="ins-label" htmlFor="password">Password</label>
          <input id="password" className="ins-input" placeholder="8+ characters" type="password"
            autoComplete="new-password" value={f.password} onChange={up("password")} required />
        </div>

        <div className="mt-4">
          <label className="ins-label" htmlFor="org">Organization name</label>
          <input id="org" className="ins-input" placeholder="Your agency or store"
            autoComplete="organization" value={f.organization_name} onChange={up("organization_name")} required />
        </div>

        <button type="submit" disabled={busy} className="ins-btn mt-6">
          {busy ? "Creating account…" : "Create account →"}
        </button>
      </form>
    </AuthShell>
  );
}
