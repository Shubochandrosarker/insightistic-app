"use client";
import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { AuthShell } from "@/components/auth/AuthShell";

export default function Login() {
  const { login } = useAuth();
  const router = useRouter();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setErr(null); setBusy(true);
    try { await login(email, password); router.push("/dashboard"); }
    catch (e: any) { setErr(e.message || "Login failed"); }
    finally { setBusy(false); }
  }

  return (
    <AuthShell title="Sign in to Insightistic">
      {err && <p className="mb-3 text-sm text-red-600">{err}</p>}
      <input className="ins-input" placeholder="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
      <input className="ins-input mt-3" placeholder="Password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
      <button onClick={submit} disabled={busy} className="ins-btn mt-4">{busy ? "Signing in…" : "Sign in"}</button>
      <div className="mt-4 flex justify-between text-sm text-slate-500">
        <Link href="/forgot-password" className="hover:text-slate-900">Forgot password?</Link>
        <Link href="/register" className="hover:text-slate-900">Create account</Link>
      </div>
    </AuthShell>
  );
}
