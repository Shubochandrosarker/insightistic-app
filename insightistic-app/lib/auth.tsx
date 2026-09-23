"use client";
import { createContext, useContext, useEffect, useState, ReactNode } from "react";
import { apiGet, apiPost, setToken } from "./api";
import { getAttributionBundle } from "./attribution";
import type { Org, User } from "./types";

interface RegisterInput {
  name: string;
  email: string;
  password: string;
  organization_name: string;
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

  async function login(email: string, password: string): Promise<LoginResult> {
    const res = await apiPost("/auth/login", { email, password });

    // MFA accounts get a short-lived challenge instead of a token.
    if (res.mfa_required) return { mfaRequired: true, challenge: res.challenge };

    return { mfaRequired: false, user: adoptSession(res) };
  }

  async function completeMfa(challenge: string, code: string): Promise<User> {
    return adoptSession(await apiPost("/auth/mfa/challenge", { challenge, code }));
  }

  /** Store the token and user from a successful sign-in response. */
  function adoptSession(res: any): User {
    setToken(res.token);
    setUser(res.user);
    setOrgs(res.organizations || []);
    return res.user as User;
  }

  async function register(p: RegisterInput) {
    // The form has a single password field; mirror it as the confirmation so
    // the backend's `confirmed` rule passes without a second input. The
    // attribution bundle (UTM/gclid first+last touch, spec §32) rides along.
    const attribution = getAttributionBundle() ?? undefined;
    const res = await apiPost("/auth/register", {
      ...p,
      password_confirmation: p.password,
      attribution,
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
  }

  /** Adopt a token issued by the OAuth callback and hydrate the session. */
  async function applyToken(token: string): Promise<User> {
    setToken(token);
    const me = await apiGet("/auth/me");
    setUser(me.user);
    setOrgs(me.organizations || []);
    return me.user as User;
  }

  function logout() {
    apiPost("/auth/logout").catch(() => {});
    setToken(null);
    setUser(null);
    setOrgs([]);
  }

  return (
    <Ctx.Provider value={{ user, orgs, loading, login, completeMfa, register, applyToken, logout }}>
      {children}
    </Ctx.Provider>
  );
}

export function useAuth() {
  const c = useContext(Ctx);
  if (!c) throw new Error("useAuth must be used within AuthProvider");
  return c;
}
