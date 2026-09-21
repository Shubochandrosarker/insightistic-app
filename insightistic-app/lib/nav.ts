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
    items: [{ label: "Overview", href: "/dashboard" }],
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
      { label: "Index Coverage", soon: true, hint: "Index coverage reporting is coming soon" },
      { label: "Bing Webmaster", soon: true, hint: "Bing Webmaster integration is coming soon" },
    ],
  },
  {
    label: "AI Visibility",
    icon: "Sparkles",
    items: [
      // Prompts/Mentions/Citations/Competitors live INSIDE the overview page
      // as cards, so only Overview is a real route for now.
      { label: "Overview", href: "/dashboard/visibility" },
      { label: "Prompts", soon: true, hint: "Prompt tracking is coming soon" },
      { label: "Mentions", soon: true, hint: "Brand mention tracking is coming soon" },
      { label: "Citations", soon: true, hint: "Citation tracking is coming soon" },
      { label: "Competitors", soon: true, hint: "Competitor visibility comparison is coming soon" },
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
      // The connector health page keeps its /dashboard/site-health route —
      // it reports plugin/sync reachability rather than crawl results, so it
      // reads better renamed inside the audit group.
      { label: "Connector health", href: "/dashboard/site-health" },
      { label: "Images", soon: true, hint: "Image audit is coming soon" },
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
      { label: "Landing Pages", soon: true, hint: "Landing page report is coming soon" },
      { label: "AI Referrals", soon: true, hint: "AI referral traffic is coming soon" },
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
    items: [
      { label: "Insights", href: "/dashboard/insights" },
      { label: "Copilot", href: "/dashboard/copilot" },
      { label: "Knowledge Bases", href: "/dashboard/knowledge" },
      { label: "Deep Dives", href: "/dashboard/intelligence" },
      { label: "Alerts", href: "/dashboard/alerts" },
      { label: "Automations", href: "/dashboard/automation" },
    ],
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
