import type { Metadata } from "next";
import "./globals.css";
import { AuthProvider } from "@/lib/auth";

export const metadata: Metadata = {
  title: "Insightistic — AI Business Analytics for WordPress & WooCommerce",
  description: "Connect your WordPress/WooCommerce site and get revenue, customer, product and AI business insights in one clean dashboard.",
};

export default function RootLayout({ children }: { children: React.ReactNode }) {
  return (
    <html lang="en">
      <body>
        <AuthProvider>{children}</AuthProvider>
      </body>
    </html>
  );
}
