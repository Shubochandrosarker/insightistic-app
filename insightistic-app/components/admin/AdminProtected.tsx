"use client";
import { ReactNode, useEffect } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import { useAuth } from "@/lib/auth";
import { BrandLoader } from "@/components/BrandLoader";
import { ShieldAlert } from "lucide-react";

/** Gate the /admin area to platform super admins only. */
export function AdminProtected({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;
    if (!user) router.replace("/login");
  }, [loading, user, router]);

  if (loading || !user) return <BrandLoader message="Checking access…" />;

  if (!user.is_super_admin) {
    return (
      <main className="flex min-h-screen flex-col items-center justify-center gap-4 bg-bg px-6 text-center">
        <span className="flex h-12 w-12 items-center justify-center rounded-2xl bg-bad/12 text-bad">
          <ShieldAlert size={24} />
        </span>
        <div>
          <h1 className="text-lg font-bold text-fg">403 — Access denied</h1>
          <p className="mt-1 text-sm text-muted">This area is for platform administrators only.</p>
        </div>
        <Link href="/dashboard" className="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand2">
          Back to dashboard
        </Link>
      </main>
    );
  }

  return <>{children}</>;
}
