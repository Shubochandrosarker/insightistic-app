/** @type {import('next').NextConfig} */

/**
 * Insightistic frontend config.
 *
 * API CONNECTION (two supported modes):
 *
 *  1) PROXY MODE  (recommended — the default)
 *     Leave NEXT_PUBLIC_API_URL empty. The browser calls the app's OWN origin
 *     (e.g. https://app.insightistic.com/api/...) and Next.js forwards those
 *     requests server-side to the Laravel API. This means NO CORS, NO
 *     mixed-content, and NO build-time URL baking — the #1 cause of the
 *     "Failed to fetch" error on signup disappears.
 *
 *     Configure the server-side target with API_PROXY_TARGET:
 *       - PM2 / single VPS:  http://127.0.0.1:8000
 *       - Docker compose:    http://api:8000
 *       - Separate API host: https://api.insightistic.com
 *
 *  2) DIRECT MODE
 *     Set NEXT_PUBLIC_API_URL=https://api.insightistic.com. The browser calls
 *     the API host directly. This requires the API to send CORS headers for the
 *     app origin (see insightistic-api/config/cors.php, already configured).
 */
const API_PROXY_TARGET = (
  process.env.API_PROXY_TARGET ||
  process.env.NEXT_PUBLIC_API_URL ||
  "http://127.0.0.1:8000"
).replace(/\/$/, "");

// Direct mode needs the API origin in connect-src; proxy mode is same-origin.
const API_ORIGIN = (process.env.NEXT_PUBLIC_API_URL || "").replace(/\/$/, "");

/**
 * Content-Security-Policy.
 *
 * 'unsafe-inline' in script-src is required today by (a) the pre-paint theme
 * snippet in app/layout.tsx and (b) Next.js's own inline RSC/hydration
 * scripts on statically prerendered pages. Tightening to nonces requires
 * dynamic rendering via middleware — tracked as a follow-up. Everything else
 * is locked down: no foreign script origins beyond the approved payment
 */
const csp = [
  "default-src 'self'",
  "script-src 'self' 'unsafe-inline' https://static.cloudflareinsights.com",
  "style-src 'self' 'unsafe-inline'",
  // data:/blob: for chart exports + inline SVG; https: for OAuth avatars and
  // white-label logos served from the API/storage origin.
  "img-src 'self' data: blob: https:",
  "font-src 'self' data:",
  "frame-src 'self'",
  `connect-src 'self'${API_ORIGIN ? ` ${API_ORIGIN}` : ""}`,
  "object-src 'none'",
  "base-uri 'self'",
  "form-action 'self'",
  "frame-ancestors 'none'",
  "upgrade-insecure-requests",
].join("; ");

const securityHeaders = [
  { key: "Content-Security-Policy", value: csp },
  // 2 years, preload-eligible. Served behind Traefik/nginx TLS termination.
  { key: "Strict-Transport-Security", value: "max-age=63072000; includeSubDomains; preload" },
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "Referrer-Policy", value: "strict-origin-when-cross-origin" },
  { key: "X-Frame-Options", value: "DENY" }, // legacy agents; CSP frame-ancestors is authoritative
  {
    key: "Permissions-Policy",
    value: "camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=()",
  },
];

const nextConfig = {
  reactStrictMode: true,
  poweredByHeader: false,
  // Avoid spawning a large page-generation pool on machines with limited RAM.
  // This app has enough dashboard routes for Next.js to default to 15 workers.
  experimental: { cpus: 2 },
  // The API proxy streams SSE (copilot chat). Next's compression middleware
  // gzips responses and holds small SSE deltas in the zlib buffer, so the
  // browser sees zero bytes for the whole answer and the outer nginx
  // read-timeout kills the "stalled" connection mid-stream ("network
  // error"). TLS+gzip terminates at nginx; this hop must stay unbuffered.
  compress: false,
  // No next/image usage in this app; keep the optimizer (and its native sharp
  // pipeline) explicitly out of the request path.
  images: { unoptimized: true },
  async headers() {
    return [{ source: "/:path*", headers: securityHeaders }];
  },
  async rewrites() {
    return [
      // Same-origin API proxy (proxy mode). Harmless in direct mode since the
      // browser then targets NEXT_PUBLIC_API_URL and never hits these paths.
      { source: "/api/:path*", destination: `${API_PROXY_TARGET}/api/:path*` },
    ];
  },
};

export default nextConfig;
