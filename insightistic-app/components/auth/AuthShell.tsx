"use client";
import Link from "next/link";
import type { ReactNode } from "react";

export function AuthShell({ title, children }: { title: string; children: ReactNode }) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
      <div className="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-7">
        <Link href="/" className="text-lg font-bold text-slate-900">Insightistic</Link>
        <h1 className="mt-4 text-xl font-semibold text-slate-900">{title}</h1>
        <div className="mt-5">{children}</div>
      </div>
      <style>{`
        .ins-input{width:100%;border:1px solid #e2e8f0;border-radius:.5rem;padding:.6rem .75rem;font-size:.9rem;color:#0f172a}
        .ins-input:focus{outline:none;border-color:#2563EB}
        .ins-btn{width:100%;background:#2563EB;color:#fff;border-radius:.5rem;padding:.6rem;font-weight:500;font-size:.9rem}
        .ins-btn:disabled{opacity:.5}
      `}</style>
    </main>
  );
}
