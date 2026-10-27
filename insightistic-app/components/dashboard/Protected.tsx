"use client";
import { ReactNode, useEffect } from "react";
import { useRouter } from "next/navigation";
import { useAuth } from "@/lib/auth";
import { Spinner } from "@/components/ui/Spinner";
import { SessionRecovery } from "@/components/auth/SessionRecovery";

export function Protected({ children }: { children: ReactNode }) {
  const { user, loading, sessionError, retrySession } = useAuth();
  const router = useRouter();
  useEffect(() => {
    if (!loading && !user && !sessionError) router.replace("/login");
  }, [loading, user, sessionError, router]);

  if (sessionError) {
    return <SessionRecovery message={sessionError} onRetry={retrySession} />;
  }

  if (loading || !user) {
    return (
      <div className="flex min-h-screen items-center justify-center bg-bg">
        <Spinner label="Loading your dashboard…" />
      </div>
    );
  }
  return <>{children}</>;
}
