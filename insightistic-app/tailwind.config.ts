import type { Config } from "tailwindcss";

/**
 * Insightistic design tokens.
 * Theme-flippable surfaces use CSS variables (see app/globals.css) so the
 * dashboard light/dark toggle works without restyling every page. Brand
 * colors are fixed (green-forward, matching the SaaS UI).
 */
const config: Config = {
  darkMode: "class",
  content: ["./app/**/*.{ts,tsx}", "./components/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        // theme-flippable semantic surfaces
        bg: "var(--ins-bg)",
        card: "var(--ins-card)",
        card2: "var(--ins-card-2)",
        line: "var(--ins-line)",
        fg: "var(--ins-fg)",
        muted: "var(--ins-muted)",

        // fixed brand palette
        ink: "#0B1110",
        ink2: "#101A18",
        panel: "#14211F",
        panel2: "#182A26",
        brand: { DEFAULT: "#00C04B", 600: "#00A341", 700: "#008E39" },
        brand2: "#00D084",
        mint: "#86DBB8",
        violet: "#6C5CE7",
        good: "#00B894",
        warn: "#F6A609",
        bad: "#EF5350",
      },
      borderRadius: {
        xl: "0.9rem",
        "2xl": "1.1rem",
      },
      boxShadow: {
        card: "0 1px 2px rgba(11,17,16,0.04), 0 8px 24px -12px rgba(11,17,16,0.10)",
      },
      fontFamily: {
        sans: ["var(--font-sans)", "ui-sans-serif", "system-ui", "-apple-system", "Segoe UI", "Roboto", "Helvetica", "Arial", "sans-serif"],
      },
    },
  },
  plugins: [],
};
export default config;
