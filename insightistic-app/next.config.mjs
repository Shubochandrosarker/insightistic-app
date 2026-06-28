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

const nextConfig = {
  reactStrictMode: true,
  async rewrites() {
    return [
      // Same-origin API proxy (proxy mode). Harmless in direct mode since the
      // browser then targets NEXT_PUBLIC_API_URL and never hits these paths.
      { source: "/api/:path*", destination: `${API_PROXY_TARGET}/api/:path*` },
    ];
  },
};

export default nextConfig;
