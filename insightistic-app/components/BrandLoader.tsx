import { BrandLogo } from "@/components/BrandLogo";

export function BrandLoader({ message = "Preparing your workspace…" }: { message?: string }) {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-5 bg-bg px-6">
      <BrandLogo className="animate-[ins-fade_0.5s_ease] rounded-lg bg-white px-3" imageClassName="h-9 w-auto" />
      <div className="h-7 w-7 animate-spin rounded-full border-2 border-brand border-t-transparent" />
      <p className="text-sm text-muted">{message}</p>
    </main>
  );
}
