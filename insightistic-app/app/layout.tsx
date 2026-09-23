import type { Metadata } from "next";
import "./globals.css";
import { AuthProvider } from "@/lib/auth";
import AttributionCapture from "@/components/AttributionCapture";
import ConsentBanner from "@/components/marketing/ConsentBanner";

export const metadata: Metadata = {
  title: "Insightistic — Search, AI Visibility & Website Intelligence",
  description:
    "Connect any website — WordPress, Shopify, Laravel or custom — and get search intelligence, AI visibility, site audits, uptime and traffic insights in one clean dashboard.",
  icons: { icon: "/logos/insightistic-logo.png", apple: "/logos/insightistic-logo.png" },
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
        <AuthProvider>
          <AttributionCapture />
          <ConsentBanner />
          {children}
        </AuthProvider>
      </body>
    </html>
  );
}
