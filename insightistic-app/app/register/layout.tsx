import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Create your Insightistic account",
  description:
    "Create an Insightistic account to connect a website and review search, AI visibility, audit, uptime and traffic intelligence.",
  alternates: { canonical: "/register" },
  robots: { index: false, follow: false },
};

export default function RegisterLayout({ children }: { children: React.ReactNode }) {
  return children;
}
