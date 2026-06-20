"use client";
import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/auth/AuthShell";

export default function Register() {
  const { register } = useAuth();
  const router = useRouter();
  const [f, setF] = useState({ name: "", organization_name: "", email: "", password: "", password_confirmation: "" });
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const up = (k: string) => (e: any) => setF({ ...f, [k]: e.target.value });

  async function submit() {
    setErr(null); setBusy(true);
    try { await register(f); router.push("/dashboard"); }
    catch (e: any) { setErr(e.data?.message || e.message || "Sign up failed"); }
    finally { setBusy(false); }
  }

  return (
    <AuthShell title="Start your free trial">
      {err && <p className="mb-3 text-sm text-red-600">{err}</p>}
      <input className="ins-input" placeholder="Your name" value={f.name} onChange={up("name")} />
      <input className="ins-input mt-3" placeholder="Organization name" value={f.organization_name} onChange={up("organization_name")} />
      <input className="ins-input mt-3" placeholder="Email" type="email" value={f.email} onChange={up("email")} />
      <input className="ins-input mt-3" placeholder="Password" type="password" value={f.password} onChange={up("password")} />
      <input className="ins-input mt-3" placeholder="Confirm password" type="password" value={f.password_confirmation} onChange={up("password_confirmation")} />
      <button onClick={submit} disabled={busy} className="ins-btn mt-4">{busy ? "Creating…" : "Create account"}</button>
      <p className="mt-4 text-sm text-slate-500">Already have an account? <Link href="/login" className="hover:text-slate-900">Sign in</Link></p>
    </AuthShell>
  );
}
