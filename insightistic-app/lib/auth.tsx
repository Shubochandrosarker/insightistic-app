"use client";
import { createContext, useContext, useEffect, useState, ReactNode } from "react";
import { apiGet, apiPost, setToken } from "./api";
import type { Org, User } from "./types";

interface RegisterInput {
  name: string;
  email: string;
  password: string;
  organization_name: string;
}

interface AuthState {
  user: User | null;
  orgs: Org[];
  loading: boolean;
  login: (email: string, password: string) => Promise<User>;
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

  async function login(email: string, password: string): Promise<User> {
    const res = await apiPost("/auth/login", { email, password });
    setToken(res.token);
    setUser(res.user);
    setOrgs(res.organizations || []);
    return res.user as User;
  }

  async function register(p: RegisterInput) {
    // The form has a single password field; mirror it as the confirmation so
    // the backend's `confirmed` rule passes without a second input.
    const res = await apiPost("/auth/register", { ...p, password_confirmation: p.password });
    setToken(res.token);
    setUser(res.user);
    setOrgs(res.organization ? [{ ...res.organization, role: "owner" }] : []);
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
    <Ctx.Provider value={{ user, orgs, loading, login, register, applyToken, logout }}>
      {children}
    </Ctx.Provider>
  );
}

export function useAuth() {
  const c = useContext(Ctx);
  if (!c) throw new Error("useAuth must be used within AuthProvider");
  return c;
}
