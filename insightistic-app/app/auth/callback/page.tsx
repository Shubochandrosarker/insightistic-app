"use client";
import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useAuth } from "@/lib/auth";

const ERRORS: Record<string, string> = {
  provider_unavailable: "That sign-in provider isn't available right now.",
  oauth_failed: "We couldn't complete sign-in with that provider. Please try again.",
  no_email: "Your provider didn't share an email address, so we can't create an account.",
};

export default function OAuthCallback() {
  const { applyToken } = useAuth();
  const router = useRouter();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const params = new URLSearchParams(window.location.hash.replace(/^#/, ""));
    const token = params.get("token");
    const err = params.get("error");

    // Don't leave the token sitting in the address bar / history.
    history.replaceState(null, "", window.location.pathname);

    if (err) {
      setError(ERRORS[err] || "Sign-in failed. Please try again.");
      return;
    }
    if (!token) {
      setError("No sign-in token was returned. Please try again.");
      return;
    }

    applyToken(token)
      .then((u) => router.replace(u?.is_super_admin ? "/admin" : "/dashboard"))
      .catch(() => setError("We couldn't finish signing you in. Please try again."));
  }, [applyToken, router]);

  return (
    <main className="flex min-h-screen items-center justify-center bg-bg px-6">
      <div className="w-full max-w-sm rounded-2xl border border-line bg-card p-8 text-center shadow-card">
        <div className="mx-auto mb-4 flex items-center justify-center gap-2">
          <span className="ins-logo-dot" />
          <span className="text-lg font-bold text-fg">Insightistic</span>
        </div>

        {error ? (
          <>
            <p className="text-sm text-bad">{error}</p>
            <Link
              href="/login"
              className="mt-5 inline-block rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand2"
            >
              Back to sign in
            </Link>
          </>
        ) : (
          <>
            <div className="mx-auto h-7 w-7 animate-spin rounded-full border-2 border-brand border-t-transparent" />
            <p className="mt-4 text-sm text-muted">Signing you in…</p>
          </>
        )}
      </div>
    </main>
  );
}
