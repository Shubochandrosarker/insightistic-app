"use client";
import Link from "next/link";
import { useDashboard } from "@/lib/dashboard";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { EmptyState } from "@/components/dashboard/EmptyState";
import { Mail } from "lucide-react";

// Email/campaign analytics arrive once email-event sync is enabled for a site.
// Until the connector streams those events we surface a clear, honest state
// rather than fabricated numbers.
export default function EmailsPage() {
  const { siteId, sites } = useDashboard();
  const site = sites.find((s) => s.id === siteId);

  if (!siteId) return <EmptyState icon={<Mail size={20} />} title="No site connected" hint="Connect a store to see email performance." />;

  const kpis = [
    { label: "Emails sent", value: "—" },
    { label: "Open rate", value: "—" },
    { label: "Click rate", value: "—" },
    { label: "Revenue from email", value: "—" },
  ];

  return (
    <div>
      <PageHeader title="Emails" subtitle={`Campaign performance and revenue from email automation for ${site?.name || "your store"}.`} />

      <div className="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
        {kpis.map((k) => (
          <div key={k.label} className="rounded-2xl border border-line bg-card p-4 shadow-card">
            <div className="text-xs font-medium text-muted">{k.label}</div>
            <div className="mt-1.5 text-[26px] font-bold text-fg">{k.value}</div>
          </div>
        ))}
      </div>

      <Card className="mt-5" title="Campaigns">
        <EmptyState
          icon={<Mail size={20} />}
          title="Email analytics aren’t streaming yet"
          hint="Turn on “Email automation” in Settings → Sync, then connect your email platform so campaign opens, clicks and revenue show up here."
          action={
            <Link href="/dashboard/settings" className="rounded-xl bg-brand px-4 py-2 text-sm font-semibold text-white hover:bg-brand2">
              Open sync settings
            </Link>
          }
        />
      </Card>
    </div>
  );
}
