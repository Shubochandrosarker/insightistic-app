"use client";
import { useState } from "react";
import { apiPost } from "@/lib/api";
import { AuthShell } from "@/components/auth/AuthShell";

export default function Forgot() {
  const [email, setEmail] = useState("");
  const [msg, setMsg] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    try { const r = await apiPost("/auth/forgot-password", { email }); setMsg(r.message); }
    catch (e: any) { setMsg(e.message); }
    finally { setBusy(false); }
  }

  return (
    <AuthShell title="Reset your password">
      {msg && <p className="mb-3 text-sm text-emerald-600">{msg}</p>}
      <input className="ins-input" placeholder="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
      <button onClick={submit} disabled={busy} className="ins-btn mt-4">{busy ? "Sending…" : "Send reset link"}</button>
    </AuthShell>
  );
}
