"use client";
import { Suspense } from "react";
import { SetPassword } from "@/components/auth/SetPassword";

export default function AcceptInvite() {
  return (
    <Suspense>
      <SetPassword heading="Accept your invite" cta="Set password & join" />
    </Suspense>
  );
}
