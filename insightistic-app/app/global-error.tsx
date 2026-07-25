"use client";

/**
 * Last-resort boundary: catches errors in the root layout itself, where the
 * normal error.tsx cannot run because the layout that would host it is the
 * thing that failed. It must therefore render its own <html>/<body>, and
 * cannot rely on the app's providers, fonts or CSS variables — so the
 * styling here is deliberately inline and self-contained.
 */
export default function GlobalError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return (
    <html lang="en">
      <body style={{ fontFamily: "system-ui, sans-serif", margin: 0, padding: "64px 24px", textAlign: "center", background: "#eef4f1", color: "#0b1110" }}>
        <h1 style={{ fontSize: 18, margin: "0 0 8px" }}>Insightistic couldn’t start</h1>
        <p style={{ fontSize: 14, color: "#5c6b66", margin: "0 0 4px" }}>
          {error.message || "An unexpected error stopped the app from loading."}
        </p>
        {error.digest && (
          <p style={{ fontSize: 12, color: "#5c6b66", fontFamily: "monospace" }}>Reference: {error.digest}</p>
        )}
        <button
          onClick={reset}
          style={{ marginTop: 24, background: "#00c04b", color: "#fff", border: 0, borderRadius: 12, padding: "10px 18px", fontSize: 14, fontWeight: 600, cursor: "pointer" }}
        >
          Reload
        </button>
      </body>
    </html>
  );
}
