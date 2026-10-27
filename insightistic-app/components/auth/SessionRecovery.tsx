"use client";

import { RefreshCw, WifiOff } from "lucide-react";

export function SessionRecovery({
  message,
  onRetry,
}: {
  message: string;
  onRetry: () => void;
}) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-bg px-6 text-center">
      <div className="w-full max-w-md rounded-2xl border border-line bg-card p-6 shadow-sm">
        <span className="mx-auto flex h-12 w-12 items-center justify-center rounded-2xl bg-warn/12 text-warn">
          <WifiOff size={24} aria-hidden="true" />
        </span>
        <h1 className="mt-4 text-lg font-bold text-fg">We could not check your session</h1>
        <p className="mt-2 text-sm leading-6 text-muted">{message}</p>
        <button
          type="button"
          onClick={onRetry}
          className="ins-tap mx-auto mt-5 inline-flex items-center justify-center gap-2 rounded-xl bg-brand px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand2"
        >
          <RefreshCw size={16} aria-hidden="true" />
          Try again
        </button>
      </div>
    </main>
  );
}
