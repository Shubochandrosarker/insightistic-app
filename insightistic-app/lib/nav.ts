/**
 * Target dashboard IA (roadmap §21 "Information architecture").
 *
 * Single source of truth for every nav surface: desktop sidebar, mobile
 * bottom tabs + More sheet, and the command palette. Pages that don't exist
 * yet carry `soon: true` and never get an href — a nav entry that 404s is
 * worse than no entry at all.
 */
export type NavItem = { label: string; href?: string; soon?: boolean; hint?: string };
export type NavGroup = { label: string; icon: string; items: NavItem[] };

export const NAV_GROUPS: NavGroup[] = [
  {
    label: "Overview",
    icon: "LayoutDashboard",
    items: [
      { label: "Overview", href: "/dashboard" },
      { label: "Add Website", href: "/dashboard/add-website" },
    ],
  },
  {
    label: "Search Intelligence",
    icon: "Search",
    items: [
      { label: "Search Performance", href: "/dashboard/seo" },
      { label: "Queries explorer", href: "/dashboard/seo/queries" },
      { label: "Pages explorer", href: "/dashboard/seo/pages" },
      { label: "Opportunities", href: "/dashboard/seo/opportunities" },
      { label: "Index Now", href: "/dashboard/indexnow" },
      { label: "Rank Tracker", href: "/dashboard/rank" },
    ],
  },
  {
    label: "AI Visibility",
    icon: "Sparkles",
    items: [
      { label: "AI Overview", href: "/dashboard/visibility" },
      { label: "AI Insights", href: "/dashboard/insights" },
      { label: "Agent JOLLY", href: "/dashboard/copilot" },
      { label: "AI Knowledge Base", href: "/dashboard/knowledge" },
      { label: "Deep Dives", href: "/dashboard/intelligence" },
      { label: "Alerts", href: "/dashboard/alerts" },
      { label: "Automations", href: "/dashboard/automation" },
    ],
  },
  {
    label: "Site Audit",
    icon: "FileSearch",
    items: [
      { label: "Overview", href: "/dashboard/audit" },
      { label: "Pages", href: "/dashboard/audit/pages" },
      { label: "Links", href: "/dashboard/audit/links" },
      { label: "Issues", href: "/dashboard/audit/issues" },
      // The connector health page reports plugin/sync reachability alongside
      // the site's crawl health, so it remains one clear destination here.
      { label: "Connector & health", href: "/dashboard/site-health" },
      { label: "Changes", href: "/dashboard/audit/changes" },
    ],
  },
  {
    label: "Performance",
    icon: "Gauge",
    items: [
      { label: "Core Web Vitals", href: "/dashboard/performance" },
      { label: "Cloudflare", href: "/dashboard/cloudflare" },
      { label: "Uptime", href: "/dashboard/uptime" },
    ],
  },
  {
    label: "Traffic",
    icon: "BarChart3",
    items: [
      { label: "Analytics", href: "/dashboard/traffic" },
    ],
  },
  {
    label: "Commerce",
    icon: "ShoppingCart",
    items: [
      { label: "Revenue", href: "/dashboard/revenue" },
      { label: "Orders", href: "/dashboard/orders" },
      { label: "Products", href: "/dashboard/products" },
      { label: "Customers", href: "/dashboard/customers" },
      { label: "Email Campaigns", href: "/dashboard/emails" },
    ],
  },
  {
    label: "Intelligence",
    icon: "Lightbulb",
    items: [{ label: "AI Workspace", href: "/dashboard/agents" }],
  },
  {
    label: "Reports",
    icon: "FileText",
    items: [{ label: "Reports & Schedules", href: "/dashboard/reports" }],
  },
  {
    // Agency workspace (roadmap doc 20): one group for the cross-site,
    // multi-client surfaces. The portfolio overview is its first page.
    label: "Agency",
    icon: "Building2",
    items: [{ label: "Portfolio", href: "/dashboard/agency" }],
  },
  {
    label: "Settings",
    icon: "Settings",
    items: [
      { label: "Sites & Connector", href: "/dashboard/settings" },
      { label: "Integrations", href: "/dashboard/integrations" },
      { label: "Team", href: "/dashboard/team" },
      { label: "Branding", href: "/dashboard/branding" },
      { label: "Licenses", href: "/dashboard/licenses" },
      { label: "Usage", href: "/dashboard/usage" },
      { label: "Billing", href: "/dashboard/billing" },
      { label: "Security", href: "/dashboard/security" },
      { label: "Privacy", href: "/dashboard/privacy" },
    ],
  },
];

/** Every route that exists today, flat — used by tests and the palette. */
export const FLAT_ROUTES: string[] = NAV_GROUPS.flatMap((g) =>
  g.items.filter((i) => i.href && !i.soon).map((i) => i.href as string),
);

/** Primary destinations shown in the desktop rail. Child routes stay in the
 * context bar so the rail remains scannable even as the product grows. */
export const PRIMARY_NAV: { label: string; icon: string; href: string }[] = NAV_GROUPS.map((group) => ({
  label: group.label,
  icon: group.icon,
  href: group.items.find((item) => item.href && !item.soon)?.href || "/dashboard",
}));
