"use client";
import { RouteError } from "@/components/dashboard/RouteError";

export default function AppError({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  return <RouteError error={error} reset={reset} scope="page" home="/" />;
}
