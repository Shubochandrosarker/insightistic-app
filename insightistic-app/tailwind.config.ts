import type { Config } from "tailwindcss";
const config: Config = {
  content: ["./app/**/*.{ts,tsx}", "./components/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        ink: "#0B0F17",
        panel: "#131925",
        line: "#222C3C",
        brand: "#2563EB",
        good: "#16A34A",
        bad: "#DC2626",
      },
    },
  },
  plugins: [],
};
export default config;
