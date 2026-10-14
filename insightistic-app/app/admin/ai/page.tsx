"use client";

import { useState } from "react";
import { apiPatch, apiPost } from "@/lib/api";
import { useApi } from "@/lib/useApi";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";

type AiConfig = {
  provider_order: string[];
  wpistic: { configured: boolean; model: string; product_id: string; base_url: string };
  openrouter: { configured: boolean; model: string; free_model: string; fallback_models: string[]; base_url: string };
  knowledge_agent: { enabled: boolean; configured: boolean; base_url: string; agent_slug: string; category: string };
  credential_status: { wpistic_configured: boolean; openrouter_configured: boolean; agent_token_configured: boolean; managed_by: string };
};

export default function AdminAiConfiguration() {
  const { data, loading, error, reload } = useApi<{ ai: AiConfig }>("/admin/ai/configuration");
  const ai = data?.ai;
  const [form, setForm] = useState<any | null>(null);
  const [busy, setBusy] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [reauth, setReauth] = useState(false);
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");

  if (loading && !ai) return <><PageHeader title="AI configuration" subtitle="Owner-controlled provider routing and shared knowledge-brain settings." /><Spinner /></>;
  if (error && !ai) return <><PageHeader title="AI configuration" subtitle="Owner-controlled provider routing and shared knowledge-brain settings." /><Card><p className="py-6 text-sm text-bad">{error}</p></Card></>;

  const values = form || {
    wpistic_model: ai?.wpistic.model || "glm-5.3",
    openrouter_paid_model: ai?.openrouter.model || "z-ai/glm-5.3-flash",
    openrouter_free_model: ai?.openrouter.free_model || "z-ai/glm-5.3-flash",
    openrouter_paid_fallback_models: (ai?.openrouter.fallback_models || []).join(", "),
    openrouter_free_fallback_models: "",
    knowledge_agent_enabled: ai?.knowledge_agent.enabled || false,
    knowledge_agent_url: ai?.knowledge_agent.base_url || "",
    knowledge_agent_slug: ai?.knowledge_agent.agent_slug || "analytics-pro",
    knowledge_agent_category: ai?.knowledge_agent.category || "analytics",
  };

  function update(key: string, value: any) { setForm({ ...values, [key]: value }); }

  async function save() {
    setBusy(true); setMessage(null);
    try {
      await apiPatch("/admin/ai/configuration", values);
      setForm(null);
      setMessage("AI routing saved. WPistic remains the first provider.");
      setReauth(false);
      reload();
    } catch (e: any) {
      if (e.status === 428) setReauth(true);
      setMessage(e.message || "Could not save AI configuration.");
    } finally { setBusy(false); }
  }

  async function confirmAndSave() {
    setBusy(true); setMessage(null);
    try {
      await apiPost("/auth/reauthenticate", { password, code: code || undefined });
      setPassword(""); setCode(""); setReauth(false);
      await save();
    } catch (e: any) {
      setMessage(e.message || "Password confirmation failed.");
      setBusy(false);
    }
  }

  return <div>
    <PageHeader title="AI configuration" subtitle="Owner-controlled provider routing and shared knowledge-brain settings." />
    <div className="grid gap-5 lg:grid-cols-2">
      <Card title="Provider order" subtitle="The order is fixed for safety; only models and shared context are editable.">
        <div className="flex flex-wrap items-center gap-2 text-sm font-semibold text-fg">
          {(ai?.provider_order || ["wpistic_gateway", "openrouter", "rule"]).map((provider, index) => <span key={provider} className="flex items-center gap-2"><Badge tone={index === 0 ? "violet" : index === 1 ? "amber" : "slate"}>{provider}</Badge>{index < 2 && <span className="text-muted">→</span>}</span>)}
        </div>
        <div className="mt-5 space-y-1 text-sm">
          <p className="flex justify-between gap-3"><span className="text-muted">WPistic gateway</span><span className="text-fg">{ai?.wpistic.configured ? `configured · ${ai.wpistic.model}` : "not configured"}</span></p>
          <p className="flex justify-between gap-3"><span className="text-muted">OpenRouter</span><span className="text-fg">{ai?.openrouter.configured ? `configured · ${ai.openrouter.model}` : "not configured"}</span></p>
          <p className="flex justify-between gap-3"><span className="text-muted">Secrets</span><span className="text-fg">Environment-managed only</span></p>
        </div>
      </Card>

      <Card title="Routing models" subtitle="Use the OpenRouter model id for your GLM 5.3 Flash plan.">
        <div className="grid gap-3 sm:grid-cols-2">
          <Field label="WPistic model" value={values.wpistic_model} onChange={(e) => update("wpistic_model", e.target.value)} />
          <Field label="OpenRouter paid model" value={values.openrouter_paid_model} onChange={(e) => update("openrouter_paid_model", e.target.value)} />
          <Field label="OpenRouter free model" value={values.openrouter_free_model} onChange={(e) => update("openrouter_free_model", e.target.value)} />
          <Field label="Paid fallback models" value={values.openrouter_paid_fallback_models} onChange={(e) => update("openrouter_paid_fallback_models", e.target.value)} placeholder="comma,separated,model-ids" />
          <div className="sm:col-span-2"><Field label="Free fallback models" value={values.openrouter_free_fallback_models} onChange={(e) => update("openrouter_free_fallback_models", e.target.value)} placeholder="Optional comma-separated model ids" /></div>
        </div>
      </Card>

      <Card title="WPistic agents and knowledge brain" subtitle="Shared category context is enrichment only. Tenant analytics and documents stay isolated in Insightistic.">
        <div className="grid gap-3 sm:grid-cols-2">
          <label className="flex items-center gap-2 text-sm text-fg sm:col-span-2"><input type="checkbox" checked={values.knowledge_agent_enabled} onChange={(e) => update("knowledge_agent_enabled", e.target.checked)} /> Enable shared WPistic agent/skills metadata</label>
          <div className="sm:col-span-2"><Field label="Knowledge-agent Worker URL" type="url" value={values.knowledge_agent_url} onChange={(e) => update("knowledge_agent_url", e.target.value)} placeholder="https://your-agent-worker.example.com" hint="Must be HTTPS. The server reads /api/agents, /api/knowledge, and /api/skills." /></div>
          <Field label="Default agent slug" value={values.knowledge_agent_slug} onChange={(e) => update("knowledge_agent_slug", e.target.value)} placeholder="analytics-pro" />
          <Field label="Default category" value={values.knowledge_agent_category} onChange={(e) => update("knowledge_agent_category", e.target.value)} placeholder="analytics" />
        </div>
        <p className="mt-4 text-xs text-muted">Worker token: {ai?.credential_status.agent_token_configured ? "configured on the API server" : "not configured on the API server"}. Tokens and payment keys cannot be edited here.</p>
      </Card>

      <Card title="Save changes" subtitle="Changing provider routing can affect AI spend and availability.">
        {message && <p className="mb-3 rounded-lg border border-line bg-card2 px-3 py-2 text-sm text-fg">{message}</p>}
        {reauth && <div className="mb-4 rounded-xl border border-amber-400/30 bg-amber-400/10 p-3"><p className="text-sm font-semibold text-fg">Confirm your password to change provider routing.</p><div className="mt-3 grid gap-3 sm:grid-cols-2"><Field label="Password" type="password" value={password} onChange={(e) => setPassword(e.target.value)} /><Field label="MFA code (if enabled)" inputMode="numeric" value={code} onChange={(e) => setCode(e.target.value)} /></div><Button className="mt-3" onClick={confirmAndSave} disabled={!password || busy}>{busy ? "Confirming…" : "Confirm and save"}</Button></div>}
        {!reauth && <Button onClick={save} disabled={busy}>{busy ? "Saving…" : "Save AI configuration"}</Button>}
      </Card>
    </div>
  </div>;
}
