"use client";
import { createContext, useContext, useEffect, useState, ReactNode } from "react";
import { apiGet, apiPost, setToken } from "./api";
import type { Org, User } from "./types";

interface AuthState {
  user: User | null;
  orgs: Org[];
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (p: { name: string; email: string; password: string; password_confirmation: string; organization_name: string }) => Promise<void>;
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

  async function login(email: string, password: string) {
    const res = await apiPost("/auth/login", { email, password });
    setToken(res.token);
    setUser(res.user);
    setOrgs(res.organizations || []);
  }

  async function register(p: any) {
    const res = await apiPost("/auth/register", p);
    setToken(res.token);
    setUser(res.user);
    setOrgs(res.organization ? [{ ...res.organization, role: "owner" }] : []);
  }

  function logout() {
    apiPost("/auth/logout").catch(() => {});
    setToken(null);
    setUser(null);
    setOrgs([]);
  }

  return <Ctx.Provider value={{ user, orgs, loading, login, register, logout }}>{children}</Ctx.Provider>;
}

export function useAuth() {
  const c = useContext(Ctx);
  if (!c) throw new Error("useAuth must be used within AuthProvider");
  return c;
}
