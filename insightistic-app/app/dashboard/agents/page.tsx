"use client";

import Link from "next/link";
import { useState } from "react";
import { useRouter } from "next/navigation";
import {
  ArrowRight, BarChart3, BookOpen, FileSearch, FileText, Globe2,
  KeyRound, Search, Sparkles, WandSparkles,
} from "lucide-react";
import { useDashboard } from "@/lib/dashboard";
import { Badge } from "@/components/ui/Badge";
import { Card } from "@/components/ui/Card";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { SignalGraphic } from "@/components/dashboard/SignalGraphic";

type Agent = {
  key: string;
  name: string;
  description: string;
  category: string;
  icon: typeof Sparkles;
  tone: string;
  skills: string[];
  starter: string;
};

const AGENTS: Agent[] = [
  {
    key: "seo",
    name: "SEO Analyst",
    description: "Turn crawl findings and page data into a prioritized SEO action plan.",
    category: "Search intelligence",
    icon: Search,
    tone: "bg-emerald-50 text-emerald-700",
    skills: ["On-page analysis", "Technical SEO", "Keyword clusters"],
    starter: "Find the highest-impact SEO fixes for this site.",
  },
  {
    key: "search",
    name: "Search Analyst",
    description: "Explain search performance changes across queries, pages, and opportunities.",
    category: "Search intelligence",
    icon: BarChart3,
    tone: "bg-sky-50 text-sky-700",
    skills: ["GSC insights", "CTR opportunities", "Rank movement"],
    starter: "What changed in search performance this month?",
  },
  {
    key: "keyword",
    name: "Keyword Analyst",
    description: "Find themes, gaps, and next targets from your real search demand.",
    category: "Search intelligence",
    icon: KeyRound,
    tone: "bg-amber-50 text-amber-700",
    skills: ["Keyword gaps", "Intent mapping", "Content briefs"],
    starter: "Build a keyword cluster from our recent search queries.",
  },
  {
    key: "article",
    name: "Article Analyst",
    description: "Review content quality, topical coverage, and practical improvements.",
    category: "Content intelligence",
    icon: FileText,
    tone: "bg-violet-50 text-violet-700",
    skills: ["Article analysis", "Internal links", "Brief generation"],
    starter: "Which articles should we improve first and why?",
  },
  {
    key: "site-audit",
    name: "Site Audit Analyst",
    description: "Translate technical issues into clear fixes ordered by impact and effort.",
    category: "Website intelligence",
    icon: FileSearch,
    tone: "bg-rose-50 text-rose-700",
    skills: ["Crawl issues", "Broken links", "Health scoring"],
    starter: "Summarize the critical issues from our latest crawl.",
  },
  {
    key: "growth",
    name: "Growth Analyst",
    description: "Connect traffic, search, performance, and commerce signals into growth actions.",
    category: "Business intelligence",
    icon: Globe2,
    tone: "bg-cyan-50 text-cyan-700",
    skills: ["GA4 analysis", "Channel mix", "Revenue signals"],
    starter: "Where is the clearest growth opportunity in our data?",
  },
  {
    key: "knowledge",
    name: "Knowledge Curator",
    description: "Keep the AI knowledge base useful, current, and grounded in your own sources.",
    category: "AI workspace",
    icon: BookOpen,
    tone: "bg-lime-50 text-lime-700",
    skills: ["Source review", "Knowledge gaps", "Citations"],
    starter: "Show me the knowledge gaps that could weaken AI answers.",
  },
];

function AgentCard({ agent, onOpen }: { agent: Agent; onOpen: (agent: Agent) => void }) {
  const Icon = agent.icon;
  return (
    <article className="group rounded-xl border border-line bg-card p-5 transition hover:-translate-y-0.5 hover:border-brand/30 hover:shadow-card">
      <div className="flex items-start gap-3">
        <span className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-xl ${agent.tone}`}>
          <Icon size={18} />
        </span>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="font-bold text-fg">{agent.name}</h2>
            <Badge tone="slate">{agent.category}</Badge>
          </div>
          <p className="mt-1.5 text-sm leading-6 text-muted">{agent.description}</p>
        </div>
      </div>
      <div className="mt-4 flex flex-wrap gap-1.5">
        {agent.skills.map((skill) => <span key={skill} className="rounded-md border border-line bg-card2 px-2 py-1 text-[11px] font-semibold text-muted">{skill}</span>)}
      </div>
      <button onClick={() => onOpen(agent)} className="mt-5 inline-flex items-center gap-1.5 text-xs font-bold text-fg transition group-hover:text-brand-700">
        Open workspace <ArrowRight size={14} />
      </button>
    </article>
  );
}

export default function AgentsPage() {
  const router = useRouter();
  const { sites, siteId, period } = useDashboard();
  const activeSite = sites.find((site) => site.id === siteId);
  const [selected, setSelected] = useState<Agent | null>(null);
  const [prompt, setPrompt] = useState("");

  function launch(agent: Agent | null, text: string) {
    const q = new URLSearchParams();
    if (agent) q.set("agent", agent.key);
    if (text.trim()) q.set("prompt", text.trim());
    if (activeSite) q.set("site_id", String(activeSite.id));
    if (period) q.set("period", String(period));
    router.push(`/dashboard/copilot?${q.toString()}`);
  }

  function openAgent(agent: Agent) {
    setSelected(agent);
    setPrompt(agent.starter);
    launch(agent, agent.starter);
  }

  function startAnalysis() {
    if (!selected && !prompt.trim()) return;
    launch(selected, prompt || selected?.starter || "");
  }

  return (
    <div>
      <PageHeader
        title="AI Workspace"
        subtitle={`${activeSite?.name || "Your website"} · focused agents for search, content, audit, and growth decisions`}
        right={<Link href="/dashboard/copilot" className="inline-flex items-center gap-2 rounded-lg bg-fg px-3.5 py-2 text-xs font-bold text-card hover:-translate-y-0.5"><WandSparkles size={14} className="text-[#f4c56a]" /> Open Agent JOLLY</Link>}
      />

      <section className="ins-agent-hero mb-6 rounded-xl border p-5 text-card shadow-[0_16px_35px_-24px_rgba(20,23,28,0.8)] sm:p-6">
        <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
          <div className="max-w-xl">
            <div className="flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.16em] text-card/60"><Sparkles size={14} className="text-[#f4c56a]" /> Analyze your data with AI</div>
            <h2 className="mt-2 text-2xl font-extrabold tracking-tight">Ask one focused question. Get an evidence-backed next step.</h2>
            <p className="mt-2 text-sm leading-6 text-card/65">Choose an agent below or send the question directly to Agent JOLLY with your connected site and date range as context.</p>
          </div>
          <div className="w-full max-w-xl lg:max-w-[33rem]">
            <label htmlFor="agent-prompt" className="sr-only">What do you want to understand?</label>
            <div className="flex flex-col gap-2 rounded-xl bg-white/10 p-2 sm:flex-row">
              <input id="agent-prompt" value={prompt} onChange={(event) => setPrompt(event.target.value)} onKeyDown={(event) => { if (event.key === "Enter") startAnalysis(); }} placeholder="What should we analyze?" className="min-h-11 flex-1 rounded-lg border border-white/10 bg-white/10 px-3 text-sm text-card outline-none placeholder:text-card/45 focus:border-[#f4c56a]" />
              <button onClick={startAnalysis} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-[#f4c56a] px-4 text-sm font-extrabold text-[#14171c] hover:bg-[#ffd77f]"><Sparkles size={15} /> Analyze</button>
            </div>
            {selected && <p className="mt-2 text-xs text-card/55">Selected agent: <span className="font-bold text-card/85">{selected.name}</span></p>}
          </div>
        </div>
        <div className="mt-4 max-w-xl opacity-90"><SignalGraphic compact /></div>
      </section>

      <div className="mb-3 flex items-end justify-between gap-3">
        <div><p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted">Agent library</p><h2 className="mt-1 text-lg font-extrabold tracking-tight text-fg">Choose a specialist</h2></div>
        <span className="text-xs text-muted">{AGENTS.length} ready-to-use agents</span>
      </div>

      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {AGENTS.map((agent, index) => <div key={agent.name} className="ins-reveal" style={{ animationDelay: `${index * 55}ms` }}><AgentCard agent={agent} onOpen={openAgent} /></div>)}
      </div>
    </div>
  );
}
