"use client";
import { useState } from "react";
import { useSearchParams, useRouter } from "next/navigation";
import Link from "next/link";
import { apiPost } from "@/lib/api";
import { AuthShell } from "@/components/auth/AuthShell";

export function SetPassword({ heading, cta }: { heading: string; cta: string }) {
  const params = useSearchParams();
  const router = useRouter();
  const token = params.get("token") || "";
  const email = params.get("email") || "";
  const [password, setPassword] = useState("");
  const [confirm, setConfirm] = useState("");
  const [err, setErr] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setErr(null); setBusy(true);
    try {
      await apiPost("/auth/reset-password", { token, email, password, password_confirmation: confirm });
      setDone(true); setTimeout(() => router.push("/login"), 1500);
    } catch (e: any) { setErr(e.message); }
    finally { setBusy(false); }
  }

  return (
    <AuthShell title={heading}>
      {done ? (
        <p className="text-sm text-emerald-600">Password set. Redirecting to sign in...</p>
      ) : (
        <>
          {err && <p className="mb-3 text-sm text-red-600">{err}</p>}
          <input className="ins-input" value={email} disabled />
          <input className="ins-input mt-3" placeholder="New password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
          <input className="ins-input mt-3" placeholder="Confirm password" type="password" value={confirm} onChange={(e) => setConfirm(e.target.value)} />
          <button onClick={submit} disabled={busy} className="ins-btn mt-4">{busy ? "Saving..." : cta}</button>
          <p className="mt-4 text-sm text-slate-500"><Link href="/login" className="hover:text-slate-900">Back to sign in</Link></p>
        </>
      )}
    </AuthShell>
  );
}
