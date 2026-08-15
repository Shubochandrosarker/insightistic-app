"use client";
import { useState } from "react";
import { useApi } from "@/lib/useApi";
import { apiPost, apiDelete } from "@/lib/api";
import { Card } from "@/components/ui/Card";
import { Badge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { Field } from "@/components/ui/Field";
import { PageHeader } from "@/components/dashboard/PageHeader";
import { Spinner } from "@/components/ui/Spinner";
import { timeAgo } from "@/lib/format";
import { ShieldCheck } from "lucide-react";

type MfaStatus = {
  enabled: boolean;
  enrolment_started: boolean;
  confirmed_at: string | null;
  recovery_codes_left: number;
  recommended: boolean;
  reauth_confirmed: boolean;
};

type Session = {
  id: number;
  name: string;
  last_used_at: string | null;
  created_at: string | null;
  current: boolean;
};

export default function SecurityPage() {
  const mfa = useApi<MfaStatus>("/auth/mfa");
  const sessions = useApi<{ sessions: Session[] }>("/auth/sessions");

  if (mfa.loading) return <Spinner />;

  return (
    <div>
      <PageHeader
        title="Security"
        subtitle="Two-factor authentication and the devices signed in to your account."
      />

      {mfa.error ? (
        <Card>
          <p className="text-sm text-bad">{mfa.error}</p>
          <Button variant="ghost" className="mt-3" onClick={mfa.reload}>Try again</Button>
        </Card>
      ) : (
        <MfaCard status={mfa.data!} onChanged={mfa.reload} />
      )}

      <SessionsCard
        sessions={sessions.data?.sessions || []}
        loading={sessions.loading}
        error={sessions.error}
        onChanged={sessions.reload}
      />
    </div>
  );
}

function MfaCard({ status, onChanged }: { status: MfaStatus; onChanged: () => void }) {
  const [busy, setBusy] = useState<string | null>(null);
  const [err, setErr] = useState<string | null>(null);
  const [confirmed, setConfirmed] = useState(status.reauth_confirmed);

  const [enrolment, setEnrolment] = useState<{ secret: string; qr_svg: string } | null>(null);
  const [code, setCode] = useState("");
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
  const [confirmOff, setConfirmOff] = useState(false);

  async function run(name: string, fn: () => Promise<unknown>) {
    setBusy(name);
    setErr(null);
    try {
      await fn();
    } catch (e: any) {
      // 428 means "confirm your password first" — send them back to the prompt
      // rather than showing a dead-end error.
      if (e?.status === 428) {
        setConfirmed(false);
        setErr("Please confirm your password again to continue.");
      } else {
        setErr(e?.message || "That didn’t work. Try again.");
      }
    } finally {
      setBusy(null);
    }
  }

  if (!confirmed)
    return (
      <Card title="Two-factor authentication">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone={status.enabled ? "green" : "slate"} dot>
            {status.enabled ? "Enabled" : "Not enabled"}
          </Badge>
          {status.recommended && !status.enabled && (
            <Badge tone="amber">Recommended for your account</Badge>
          )}
        </div>
        <p className="mt-3 text-sm text-muted">
          Changing two-factor settings requires your password, so a stolen session can’t swap it
          for someone else’s.
        </p>
        <ReauthForm
          onConfirmed={() => { setConfirmed(true); setErr(null); onChanged(); }}
          mfaEnabled={status.enabled}
        />
        {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}
      </Card>
    );

  return (
    <Card title="Two-factor authentication">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          <ShieldCheck size={18} className={status.enabled ? "text-good" : "text-muted"} />
          <Badge tone={status.enabled ? "green" : "slate"} dot>
            {status.enabled ? "Enabled" : "Not enabled"}
          </Badge>
          {status.enabled && (
            <span className="text-xs text-muted">{status.recovery_codes_left} recovery codes left</span>
          )}
        </div>
      </div>

      {err && <p className="mt-3 rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}

      {recoveryCodes && (
        <div className="mt-4 rounded-xl border border-warn/30 bg-warn/10 p-4">
          <p className="text-sm font-semibold text-fg">Save your recovery codes</p>
          <p className="mt-1 text-xs text-muted">
            Each code works once and lets you in if you lose your authenticator. This is the only
            time they’re shown.
          </p>
          <ul className="mt-3 grid grid-cols-2 gap-1.5 font-mono text-sm text-fg">
            {recoveryCodes.map((c) => <li key={c}>{c}</li>)}
          </ul>
          <Button variant="ghost" className="mt-3" onClick={() => setRecoveryCodes(null)}>
            I’ve saved them
          </Button>
        </div>
      )}

      {!status.enabled && !enrolment && (
        <Button
          className="mt-4"
          disabled={busy === "setup"}
          onClick={() =>
            run("setup", async () => {
              setEnrolment(await apiPost("/auth/mfa/setup"));
            })
          }
        >
          {busy === "setup" ? "Preparing…" : "Set up two-factor authentication"}
        </Button>
      )}

      {!status.enabled && enrolment && (
        <div className="mt-4">
          <p className="text-sm text-fg">1. Scan this with your authenticator app</p>
          <div className="mt-2 inline-block rounded-xl border border-line bg-white p-3">
            {/* eslint-disable-next-line @next/next/no-img-element -- data:
                URI, not an optimizable remote asset. Rendered as an <img>
                src rather than dangerouslySetInnerHTML: a data:image/svg+xml
                src is rasterized, not parsed as live DOM, so it can't
                execute script/event-handler content even though this SVG
                is server-generated (BaconQrCode) and not attacker input
                today — this removes the injection vector regardless of
                what generates it in the future. */}
            <img
              src={`data:image/svg+xml;base64,${btoa(unescape(encodeURIComponent(enrolment.qr_svg)))}`}
              alt="Two-factor authentication QR code"
              width={200}
              height={200}
            />
          </div>
          <p className="mt-2 text-xs text-muted">
            Can’t scan? Enter this key manually: <span className="font-mono text-fg">{enrolment.secret}</span>
          </p>

          <p className="mt-4 text-sm text-fg">2. Enter the 6-digit code it shows</p>
          <div className="mt-2 max-w-xs">
            <Field value={code} onChange={(e) => setCode(e.target.value)} placeholder="123456" inputMode="numeric" />
          </div>
          <Button
            className="mt-3"
            disabled={code.trim().length < 6 || busy === "confirm"}
            onClick={() =>
              run("confirm", async () => {
                const res = await apiPost("/auth/mfa/confirm", { code: code.trim() });
                setRecoveryCodes(res.recovery_codes);
                setEnrolment(null);
                setCode("");
                onChanged();
              })
            }
          >
            {busy === "confirm" ? "Verifying…" : "Turn on two-factor"}
          </Button>
        </div>
      )}

      {status.enabled && (
        <div className="mt-4 flex flex-wrap gap-2">
          <Button
            variant="ghost"
            disabled={busy === "codes"}
            onClick={() =>
              run("codes", async () => {
                const res = await apiPost("/auth/mfa/recovery-codes");
                setRecoveryCodes(res.recovery_codes);
                onChanged();
              })
            }
          >
            {busy === "codes" ? "Generating…" : "Regenerate recovery codes"}
          </Button>

          {confirmOff ? (
            <span className="inline-flex items-center gap-2">
              <Button
                variant="danger"
                disabled={busy === "disable"}
                onClick={() =>
                  run("disable", async () => {
                    await apiDelete("/auth/mfa");
                    setConfirmOff(false);
                    onChanged();
                  })
                }
              >
                {busy === "disable" ? "Turning off…" : "Confirm turn off"}
              </Button>
              <Button variant="ghost" onClick={() => setConfirmOff(false)}>Cancel</Button>
            </span>
          ) : (
            <Button variant="danger" onClick={() => setConfirmOff(true)}>Turn off two-factor</Button>
          )}
        </div>
      )}
    </Card>
  );
}

function ReauthForm({ onConfirmed, mfaEnabled }: { onConfirmed: () => void; mfaEnabled: boolean }) {
  const [password, setPassword] = useState("");
  const [code, setCode] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  return (
    <div className="mt-4 max-w-sm space-y-2">
      <Field
        label="Password"
        type="password"
        autoComplete="current-password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
      />
      {mfaEnabled && (
        <Field
          label="Authentication code"
          inputMode="numeric"
          autoComplete="one-time-code"
          value={code}
          onChange={(e) => setCode(e.target.value)}
        />
      )}
      {err && <p className="rounded-lg border border-bad/25 bg-bad/10 px-3 py-2 text-xs text-bad">{err}</p>}
      <Button
        disabled={!password || busy}
        onClick={async () => {
          setBusy(true);
          setErr(null);
          try {
            await apiPost("/auth/reauthenticate", { password, code: code || undefined });
            setPassword("");
            setCode("");
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

function SessionsCard({
  sessions,
  loading,
  error,
  onChanged,
}: {
  sessions: Session[];
  loading: boolean;
  error: string | null;
  onChanged: () => void;
}) {
  const [busy, setBusy] = useState<string | null>(null);

  return (
    <Card className="mt-5" title="Active sessions">
      {loading ? (
        <Spinner />
      ) : error ? (
        <p className="text-sm text-bad">{error}</p>
      ) : (
        <>
          <ul className="divide-y divide-line">
            {sessions.map((s) => (
              <li key={s.id} className="flex items-center justify-between gap-3 py-2.5">
                <div>
                  <div className="text-sm font-medium text-fg">
                    {s.name}
                    {s.current && <Badge tone="green" className="ml-2">This device</Badge>}
                  </div>
                  <div className="text-xs text-muted">
                    Last used {s.last_used_at ? timeAgo(s.last_used_at) : "never"}
                  </div>
                </div>
                {!s.current && (
                  <Button
                    variant="danger"
                    disabled={busy === String(s.id)}
                    onClick={async () => {
                      setBusy(String(s.id));
                      try {
                        await apiDelete(`/auth/sessions/${s.id}`);
                        onChanged();
                      } finally {
                        setBusy(null);
                      }
                    }}
                  >
                    Sign out
                  </Button>
                )}
              </li>
            ))}
          </ul>

          {sessions.length > 1 && (
            <Button
              variant="ghost"
              className="mt-3"
              disabled={busy === "others"}
              onClick={async () => {
                setBusy("others");
                try {
                  await apiPost("/auth/sessions/revoke-others");
                  onChanged();
                } finally {
                  setBusy(null);
                }
              }}
            >
              {busy === "others" ? "Signing out…" : "Sign out every other device"}
            </Button>
          )}
        </>
      )}
    </Card>
  );
}
