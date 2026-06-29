export type Role = "owner" | "admin" | "analyst" | "client_viewer";

export interface User { id: number; name: string; email: string; is_super_admin?: boolean; }
export interface Org { id: number; name: string; slug: string; role?: Role; }
export interface Site {
  id: number; name: string; domain?: string; platform?: string;
  connection_status: string; last_sync_at?: string | null;
  wc_version?: string; wp_version?: string; plugin_version?: string;
  currency?: string; timezone?: string;
}
export interface Metrics {
  revenue: number; orders: number; refunds: number; products_sold: number;
  new_customers: number; returning_customers: number; failed_orders: number;
  average_order_value: number;
}
export type Deltas = Record<string, number | null>;
export interface DaySeries { date: string; revenue?: number; orders?: number; refunds?: number; failed?: number; }
export interface Insight {
  id: number; type: string; title: string; summary: string; recommendation: string;
  severity: "low" | "medium" | "high"; priority_score: number; status: string; created_at: string;
}
export interface Report {
  id: number; title: string; report_type: string; period_start: string; period_end: string;
  pdf_link?: string; html_link?: string; sent_to?: string; sent_at?: string;
}
