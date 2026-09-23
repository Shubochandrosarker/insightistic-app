import { defineConfig } from "vitest/config";
import react from "@vitejs/plugin-react";
import { fileURLToPath } from "node:url";

/**
 * Component tests run in jsdom against the real components — the API layer is
 * stubbed per test rather than the components being rewritten for testability.
 * Next-specific modules (navigation, font loader) are aliased in setup.ts.
 */
export default defineConfig({
  plugins: [react()],
  test: {
    environment: "jsdom",
    globals: true,
    setupFiles: ["./tests/setup.ts"],
    include: ["tests/**/*.test.{ts,tsx}"],
    // React/jsdom suites are intentionally run in parallel in CI. Ten seconds
    // keeps legitimate userEvent flows from timing out under a busy Windows
    // worker while still catching genuinely stuck tests promptly.
    testTimeout: 10_000,
    hookTimeout: 10_000,
    // The production build is verified separately; tests must not depend on it.
    restoreMocks: true,
  },
  resolve: {
    alias: {
      "@": fileURLToPath(new URL("./", import.meta.url)),
    },
  },
});
