"use client";
import { Protected } from "@/components/dashboard/Protected";
import { Sidebar } from "@/components/dashboard/Sidebar";
import { Topbar } from "@/components/dashboard/Topbar";
import { DashboardProvider } from "@/lib/dashboard";

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  return (
    <Protected>
      <DashboardProvider>
        <div className="flex min-h-screen bg-bg text-fg">
          <Sidebar />
          <div className="flex min-w-0 flex-1 flex-col">
            <Topbar />
            <main className="ins-scroll flex-1 overflow-x-hidden px-5 py-7 lg:px-8">
              <div className="mx-auto max-w-[1200px]">{children}</div>
            </main>
          </div>
        </div>
      </DashboardProvider>
    </Protected>
  );
}
