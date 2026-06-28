import type { Metadata } from "next";
import "./globals.css";
import { AuthProvider } from "@/lib/auth";

export const metadata: Metadata = {
  title: "Insightistic — AI Business Analytics for WordPress & WooCommerce",
  description:
    "Connect your WordPress/WooCommerce site and get revenue, customer, product and AI business insights in one clean dashboard.",
};

// Apply the saved theme before first paint to avoid a flash of the wrong theme.
const themeScript = `
try {
  var t = localStorage.getItem('ins_theme');
  if (t === 'dark') document.documentElement.classList.add('dark');
} catch (e) {}
`;

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en" suppressHydrationWarning>
      <head>
        <script dangerouslySetInnerHTML={{ __html: themeScript }} />
      </head>
      <body>
        <AuthProvider>{children}</AuthProvider>
      </body>
    </html>
  );
}
