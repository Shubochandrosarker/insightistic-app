"use client";
import { useEffect, useState, useCallback, useRef } from "react";
import { apiGet } from "./api";

/**
 * Fetch-on-mount hook with a sequence guard: when deps change quickly
 * (site switch, period switch) two requests race, and without the guard the
 * SLOWER, OLDER response can resolve last and paint data for the wrong
 * site/period. Only the most recent call may commit state.
 */
export function useApi<T = any>(path: string | null, deps: any[] = []) {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState(!!path);
  const [error, setError] = useState<string | null>(null);
  const pending = useRef(0);

  const reload = useCallback(() => {
    if (!path) return;
    let cancelled = false;
    pending.current += 1;
    const seq = pending.current;

    setLoading(true);
    setError(null);
    apiGet(path)
      .then((d) => {
        if (!cancelled && seq === pending.current) setData(d);
      })
      .catch((e) => {
        if (!cancelled && seq === pending.current) setError(e.message);
      })
      .finally(() => {
        if (!cancelled && seq === pending.current) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [path]);

  useEffect(() => { const cleanup = reload(); return cleanup; /* eslint-disable-next-line */ }, [path, ...deps]);

  return { data, loading, error, reload };
}
