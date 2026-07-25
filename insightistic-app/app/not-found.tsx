import Link from "next/link";

export default function NotFound() {
  return (
    <div style={{ padding: "64px 24px", textAlign: "center" }}>
      <h1 className="text-lg font-semibold text-fg">Page not found</h1>
      <p className="mt-2 text-sm text-muted">
        That link doesn’t point anywhere in Insightistic.
      </p>
      <Link
        href="/dashboard"
        className="mt-6 inline-block rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand2"
      >
        Go to your dashboard
      </Link>
    </div>
  );
}
