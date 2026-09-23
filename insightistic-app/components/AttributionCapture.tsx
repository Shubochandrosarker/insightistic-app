"use client";

import { useEffect } from "react";
import { captureAttribution } from "@/lib/attribution";

/** Mount once in the root layout: harvests visit attribution (spec §32). */
export default function AttributionCapture() {
  useEffect(() => {
    captureAttribution();
  }, []);

  return null;
}
