export function SkeletonCard({ lines = 3, className = "" }: { lines?: number; className?: string }) {
  return (
    <div className={`rounded-2xl border border-line bg-card p-5 shadow-card ${className}`}>
      <div className="ins-skeleton mb-3 h-4 w-1/3 rounded" />
      {Array.from({ length: lines }).map((_, i) => (
        <div key={i} className="ins-skeleton mb-2 h-3 rounded" style={{ width: `${90 - i * 12}%` }} />
      ))}
    </div>
  );
}

export function SkeletonGrid({ count = 8 }: { count?: number }) {
  return (
    <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
      {Array.from({ length: count }).map((_, i) => (
        <div key={i} className="rounded-2xl border border-line bg-card p-4 shadow-card">
          <div className="ins-skeleton mb-2 h-3 w-1/2 rounded" />
          <div className="ins-skeleton h-6 w-2/3 rounded" />
        </div>
      ))}
    </div>
  );
}
