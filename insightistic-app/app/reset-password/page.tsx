"use client";
import { Suspense } from "react";
import { SetPassword } from "@/components/auth/SetPassword";

export default function ResetPasswordPage() {
  return (
    <Suspense>
      <SetPassword heading="Set a new password" cta="Update password" />
    </Suspense>
  );
}
