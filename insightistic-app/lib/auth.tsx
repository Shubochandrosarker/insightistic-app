"use client";
import { createContext, useCallback, useContext, useEffect, useMemo, useState, ReactNode } from "react";
import { apiGet, apiPost, setToken, clearImpersonation } from "./api";
import { getAttributionBundle } from "./attribution";
import { getMarketingConsent } from "./consent";
import type { Org, User } from "./types";

interface RegisterInput {
  name: string;
  email: string;
  password: string;
  organization_name: string;
  marketing_consent?: boolean;
}

export type LoginResult =
  | { mfaRequired: false; user: User }
  | { mfaRequired: true; challenge: string };

interface AuthState {
  user: User | null;
  orgs: Org[];
  loading: boolean;
  /**
   * Resolves to the signed-in user, or to an MFA challenge when the account
   * has two-factor enabled — the password alone never yields a session.
   */
  login: (email: string, password: string) => Promise<LoginResult>;
  completeMfa: (challenge: string, code: string) => Promise<User>;
  register: (p: RegisterInput) => Promise<void>;
  applyToken: (token: string) => Promise<User>;
  logout: () => void;
}

const Ctx = createContext<AuthState | null>(null);

/**
 * The context value and every function in it are referentially stable across
 * re-renders. Pages like the OAuth callback hang effects off these callbacks;
 * an unstable identity re-runs those effects at arbitrary times (e.g. after
 * the callback hash has already been consumed) and manufactured phantom
 * "no sign-in token" errors right before the dashboard loaded.
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [orgs, setOrgs] = useState<Org[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    (async () => {
      try {
        const me = await apiGet("/auth/me");
        setUser(me.user);
        setOrgs(me.organizations || []);
      } catch {
        setToken(null);
      } finally {
        setLoading(false);
      }
    })();
  }, []);

  /** Store the token and user from a successful sign-in response. */
  const adoptSession = useCallback((res: any): User => {
    setToken(res.token);
    setUser(res.user);
    setOrgs(res.organizations || []);
    return res.user as User;
  }, []);

  const login = useCallback(async (email: string, password: string): Promise<LoginResult> => {
    const res = await apiPost("/auth/login", { email, password });

    // MFA accounts get a short-lived challenge instead of a token.
    if (res.mfa_required) return { mfaRequired: true, challenge: res.challenge };

    return { mfaRequired: false, user: adoptSession(res) };
  }, [adoptSession]);

  const completeMfa = useCallback(async (challenge: string, code: string): Promise<User> => {
    return adoptSession(await apiPost("/auth/mfa/challenge", { challenge, code }));
  }, [adoptSession]);

  const register = useCallback(async (p: RegisterInput) => {
    // The form has a single password field; mirror it as the confirmation so
    // the backend's `confirmed` rule passes without a second input. The
    // attribution bundle (UTM/gclid first+last touch, spec §32) rides along.
    const attribution = getAttributionBundle() ?? undefined;
    const res = await apiPost("/auth/register", {
      ...p,
      password_confirmation: p.password,
      attribution,
      marketing_consent: p.marketing_consent ?? getMarketingConsent() === true,
    });
    setToken(res.token);
    setUser(res.user);
    setOrgs(res.organization ? [{ ...res.organization, role: "owner" }] : []);
    // The raw license key appears exactly once — in this response. Stash it in
    // sessionStorage so the Licenses page can display it a single time; it is
    // cleared the moment the user confirms they saved it.
    if (res.license_key && typeof window !== "undefined") {
      sessionStorage.setItem("ins_license_key_once", res.license_key);
    }
  }, []);

  /** Adopt a token issued by the OAuth callback and hydrate the session. */
  const applyToken = useCallback(async (token: string): Promise<User> => {
    setToken(token);
    const me = await apiGet("/auth/me");
    setUser(me.user);
    setOrgs(me.organizations || []);
    return me.user as User;
  }, []);

  const logout = useCallback(() => {
    apiPost("/auth/logout").catch(() => {});
    setToken(null);
    setUser(null);
    setOrgs([]);
    // Sign-out ends EVERYTHING for this browser, not just the bearer: a
    // leftover impersonation stash would resurrect a stale "viewing as"
    // banner for the next person who signs in here, and its Exit button
    // would silently swap their session to the previous admin's token.
    // The persisted site selection is account-scoped data — drop it too so
    // the next account doesn't start pinned to a site it can't see.
    clearImpersonation();
    if (typeof window !== "undefined") localStorage.removeItem("ins_site");
  }, []);

  const value = useMemo<AuthState>(
    () => ({ user, orgs, loading, login, completeMfa, register, applyToken, logout }),
    [user, orgs, loading, login, completeMfa, register, applyToken, logout],
  );

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}

export function useAuth() {
  const c = useContext(Ctx);
  if (!c) throw new Error("useAuth must be used within AuthProvider");
  return c;
}
