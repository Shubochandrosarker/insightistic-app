"use client";
import { Protected } from "@/components/dashboard/Protected";
import { Sidebar } from "@/components/dashboard/Sidebar";
import { Topbar } from "@/components/dashboard/Topbar";
import { DashboardProvider } from "@/lib/dashboard";

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <Protected>
      <DashboardProvider>
        <div className="flex min-h-screen bg-ink text-slate-100">
          <Sidebar />
          <div className="flex min-w-0 flex-1 flex-col">
            <Topbar />
            <main className="flex-1 p-5">{children}</main>
          </div>
        </div>
      </DashboardProvider>
    </Protected>
  );
}
