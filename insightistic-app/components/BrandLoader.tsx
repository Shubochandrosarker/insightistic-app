export function BrandLoader({ message = "Preparing your workspace…" }: { message?: string }) {
  return (
    <main className="flex min-h-screen flex-col items-center justify-center gap-5 bg-bg px-6">
      <div className="flex items-center gap-2 animate-[ins-fade_0.5s_ease]">
        <span className="ins-logo-dot" />
        <span className="text-xl font-bold tracking-tight text-fg">Insightistic</span>
      </div>
      <div className="h-7 w-7 animate-spin rounded-full border-2 border-brand border-t-transparent" />
      <p className="text-sm text-muted">{message}</p>
    </main>
  );
}
