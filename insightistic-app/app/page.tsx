"use client";
import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { BrandLoader } from "@/components/BrandLoader";

/**
 * App root gate (app.insightistic.com/).
 * This subdomain is the SaaS app only — the marketing site lives on WordPress
 * at insightistic.com. So the root never renders a marketing page; it routes:
 *   - not logged in         -> /login
 *   - logged in (normal)    -> /dashboard
 *   - logged in super admin -> /admin
 */
export default function Root() {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;
    if (!user) router.replace("/login");
    else if (user.is_super_admin) router.replace("/admin");
    else router.replace("/dashboard");
  }, [user, loading, router]);

  return <BrandLoader />;
}
