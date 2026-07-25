"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPost, apiPatch, apiDelete, getToken } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { timeAgo } from "@/lib/format";
import { ShieldAlert } from "lucide-react";

type Overview = {
  retention: { days: number | null; unlimited: boolean; applies_to: string; excludes: string };
  ai: { external_processing_allowed: boolean };
  deletion: { requested_at: string | null; scheduled_for: string | null; grace_days: number };
  exports: {
    id: number;
    status: string;
    size_bytes: number | null;
    error: string | null;
    requested_at: string | null;
    expires_at: string | null;
    downloadable: boolean;
  }[];
};

const EXPORT_TONE: Record<string, "green" | "amber" | "red" | "slate"> = {
  ready: "green",
  queued: "amber",
  processing: "amber",
  failed: "red",
  expired: "slate",
};

export default function PrivacyPage() {
  const { data, loading, error, reload } = useApi<Overview>("/privacy/overview");
  const [confirmed, setConfirmed] = useState(false);

  if (loading) return <Spinner />;

  if (error)
    return (
      <div>
        <PageHeader title="Privacy" />
        <Card>
          <p className="text-sm text-bad">{error}</p>
          <Button variant="ghost" className="mt-3" onClick={reload}>Try again</Button>
        </Card>
      </div>
    );

  const d = data!;

  return (
    <div>
      <PageHeader
        title="Privacy & data"
        subtitle="Export your data, control AI processing, and manage retention or deletion."
      />

      {d.deletion.scheduled_for && (
        <div className="mb-4 rounded-xl border border-bad/30 bg-bad/10 px-4 py-3 text-sm text-bad">
          <strong>This organization is scheduled for deletion</strong> on{" "}
          {new Date(d.deletion.scheduled_for).toLocaleString()}. Everything will be permanently
          removed. You can still cancel below.
        </div>
      )}

      <Card title="Data retention">
        <p className="text-sm text-fg">
          {d.retention.unlimited
            ? "Your plan keeps analytics history indefinitely."
            : `Your plan keeps ${d.retention.days} days of analytics history.`}
        </p>
        <p className="mt-2 text-xs text-muted">
          Applies to: {d.retention.applies_to}. Never deleted: {d.retention.excludes}.
        </p>
      </Card>

      <Card className="mt-5" title="AI processing">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="text-sm text-fg">
              Send data to external AI providers for insights and Copilot answers
            </p>
            <p className="mt-1 text-xs text-muted">
              Turn this off to keep your data away from third-party models. Insights fall back to
              the built-in rule provider.
            </p>
          </div>
          <Badge tone={d.ai.external_processing_allowed ? "green" : "slate"} dot>
            {d.ai.external_processing_allowed ? "Allowed" : "Off"}
          </Badge>
        </div>

        {!confirmed ? (
          <ReauthPrompt onConfirmed={() => setConfirmed(true)} />
        ) : (
          <Button
            variant="ghost"
            className="mt-4"
            onClick={async () => {
              await apiPatch("/privacy/ai-processing", {
                allow_external_ai: !d.ai.external_processing_allowed,
              });
              reload();
            }}
          >
            {d.ai.external_processing_allowed ? "Turn off external AI" : "Allow external AI"}
          </Button>
        )}
      </Card>

      {confirmed && (
        <>
          <ExportsCard exports={d.exports} onChanged={reload} />
          <DeletionCard deletion={d.deletion} onChanged={reload} />
        </>
      )}
    </div>
  );
}

function ReauthPrompt({ onConfirmed }: { onConfirmed: () => void }) {
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  return (
    <div className="mt-4 max-w-sm space-y-2 rounded-xl border border-line bg-card2 p-4">
      <p className="text-xs text-muted">
        Confirm your password to manage exports, AI processing and deletion.
      </p>
      <Field label="Password" type="password" autoComplete="current-password"
        value={password} onChange={(e) => setPassword(e.target.value)} />
      <Field label="Authentication code (if enabled)" inputMode="numeric" autoComplete="one-time-code"
        value={code} onChange={(e) => setCode(e.target.value)} />
      {err && <p className="rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}
      <Button
        disabled={!password || busy}
        onClick={async () => {
          setBusy(true);
          setErr(null);
          try {
            await apiPost("/auth/reauthenticate", { password, code: code || undefined });
            onConfirmed();
          } catch (e: any) {
            setErr(e?.message || "Could not confirm your password.");
          } finally {
            setBusy(false);
          }
        }}
      >
        {busy ? "Confirming…" : "Confirm"}
      </Button>
    </div>
  );
}

function ExportsCard({ exports, onChanged }: { exports: Overview["exports"]; onChanged: () => void }) {
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  return (
    <Card className="mt-5" title="Export your data">
      <p className="text-sm text-muted">
        A zip of everything this organization owns, one file per table. Credentials — connector
        secrets, API tokens and two-factor secrets — are deliberately excluded.
      </p>

      {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}

      <Button
        className="mt-3"
        disabled={busy}
        onClick={async () => {
          setBusy(true);
          setErr(null);
          try {
            await apiPost("/privacy/exports");
            onChanged();
          } catch (e: any) {
            setErr(e?.message || "Could not start the export.");
          } finally {
            setBusy(false);
          }
        }}
      >
        {busy ? "Starting…" : "Request an export"}
      </Button>

      {exports.length > 0 && (
        <ul className="mt-4 divide-y divide-line">
          {exports.map((e) => (
            <li key={e.id} className="flex flex-wrap items-center justify-between gap-2 py-2.5">
              <div>
                <div className="flex items-center gap-2">
                  <Badge tone={EXPORT_TONE[e.status] ?? "slate"} dot>{e.status}</Badge>
                  {e.size_bytes && (
                    <span className="text-xs text-muted">{(e.size_bytes / 1024 / 1024).toFixed(1)} MB</span>
                  )}
                </div>
                <div className="mt-1 text-xs text-muted">
                  Requested {e.requested_at ? timeAgo(e.requested_at) : "—"}
                  {e.expires_at && e.downloadable && ` · expires ${timeAgo(e.expires_at)}`}
                </div>
                {e.error && <div className="mt-1 text-xs text-bad">{e.error}</div>}
              </div>

              {e.downloadable && (
                <a
                  href={`/api/privacy/exports/${e.id}/download`}
                  onClick={(ev) => {
                    // The download endpoint needs the bearer token, which a
                    // plain anchor cannot send — fetch it and save the blob.
                    ev.preventDefault();
                    downloadExport(e.id).catch(() => setErr("Could not download that export."));
                  }}
                  className="rounded-xl border border-line px-3 py-1.5 text-xs font-semibold text-fg hover:border-brand/40"
                >
                  Download
                </a>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/** Fetch the archive with the bearer token, then hand it to the browser. */
async function downloadExport(id: number) {
  const res = await fetch(`/api/privacy/exports/${id}/download`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  });

  if (!res.ok) throw new Error("download failed");

  const blob = await res.blob();
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = `insightistic-export-${id}.zip`;
  a.click();
  URL.revokeObjectURL(url);
}

function DeletionCard({
  deletion,
  onChanged,
}: {
  deletion: Overview["deletion"];
  onChanged: () => void;
}) {
  const [name, setName] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  if (deletion.scheduled_for)
    return (
      <Card className="mt-5" title="Delete this organization">
        <p className="text-sm text-fg">
          Deletion is scheduled for {new Date(deletion.scheduled_for).toLocaleString()}.
        </p>
        <p className="mt-1 text-xs text-muted">
          Until then nothing is removed and you can still cancel.
        </p>
        <Button
          className="mt-3"
          disabled={busy}
          onClick={async () => {
            setBusy(true);
            try {
              await apiDelete("/privacy/deletion");
              onChanged();
            } finally {
              setBusy(false);
            }
          }}
        >
          {busy ? "Cancelling…" : "Cancel deletion"}
        </Button>
      </Card>
    );

  return (
    <Card className="mt-5" title="Delete this organization">
      <div className="flex items-start gap-2">
        <ShieldAlert size={18} className="mt-0.5 shrink-0 text-bad" />
        <p className="text-sm text-muted">
          Permanently removes every site, order, report, knowledge base and conversation belonging
          to this organization, plus accounts that belong to no other organization. You have{" "}
          {deletion.grace_days} days to change your mind before anything is removed.
        </p>
      </div>

      <div className="mt-3 max-w-sm">
        <Field
          label="Type the organization name to confirm"
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
      </div>

      {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}

      <Button
        variant="danger"
        className="mt-3"
        disabled={!name || busy}
        onClick={async () => {
          setBusy(true);
          setErr(null);
          try {
            await apiPost("/privacy/deletion", { confirm_name: name });
            setName("");
            onChanged();
          } catch (e: any) {
            setErr(e?.message || "Could not schedule deletion.");
          } finally {
            setBusy(false);
          }
        }}
      >
        {busy ? "Scheduling…" : "Schedule deletion"}
      </Button>
    </Card>
  );
}
