"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/auth/AuthShell";
import { OAuthButtons } from "@/components/auth/OAuthButtons";
import { TRIAL_DAYS } from "@/lib/trial";

const PLAN_LABELS: Record<string, string> = {
  free: "Free Connected",
  starter: "Starter",
  growth: "Growth",
  agency: "Agency",
  agency_pro: "Agency Pro",
  angel_solo: "Angel Solo lifetime",
  angel_studio: "Angel Studio lifetime",
  angel_agency: "Angel Agency lifetime",
  founder_tier_1: "Angel Solo lifetime",
  founder_tier_2: "Angel Studio lifetime",
  founder_tier_3: "Angel Agency lifetime",
};

export default function Register() {
  const { register, user, loading } = useAuth();
  const router = useRouter();
  const [f, setF] = useState({ name: "", email: "", password: "", organization_name: "" });
  const [plan, setPlan] = useState<string | null>(null);
  const [interval, setInterval] = useState<"monthly" | "yearly">("monthly");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const isLifetimePlan = Boolean(plan?.startsWith("angel_"));
  const up = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) =>
    setF({ ...f, [k]: e.target.value });

  useEffect(() => {
    if (!loading && user) {
      const nextPlan = new URLSearchParams(window.location.search).get("plan");
      router.replace(nextPlan ? `/dashboard/billing?plan=${encodeURIComponent(nextPlan)}` : (user.is_super_admin ? "/admin" : "/dashboard"));
    }
  }, [user, loading, router]);

  // Read ?plan= from the marketing-site CTA without forcing a Suspense boundary.
  useEffect(() => {
    const p = new URLSearchParams(window.location.search).get("plan");
    if (p && PLAN_LABELS[p]) setPlan(p);
    const selectedInterval = new URLSearchParams(window.location.search).get("interval");
    if (selectedInterval === "yearly") setInterval("yearly");
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
      // Land on Licenses first: the one-time key card is the first onboarding step.
      if (!plan || plan === "free") {
        router.push("/dashboard/licenses");
      } else {
        router.push(`/dashboard/billing?plan=${plan}&interval=${interval}`);
      }
    } catch (e: any) {
      setErr(e.message || "Sign up failed");
      setBusy(false);
    }
  }

  return (
    <AuthShell
      title={isLifetimePlan ? "Create your Founding Angel account" : "Create your free account"}
      subtitle={isLifetimePlan ? "No free trial for LTD. After account setup, Stripe opens by default; Paddle is also available." : `Explore the free workspace, then start a ${TRIAL_DAYS}-day trial on any regular subscription plan. No card required.`}
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
          <span className="ins-logo-dot" /> {PLAN_LABELS[plan]} selected
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
