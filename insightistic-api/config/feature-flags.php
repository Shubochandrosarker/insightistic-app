<?php

// Roadmap feature flags (docs/roadmap-2026/35-CODING-STANDARDS.md).
// Each flag has a defined removal condition; do not add permanent flags here.

return [

    // Phase 7/8: technical crawler + change monitoring.
    'crawler' => env('FEATURE_CRAWLER', false),

    // Phase 10: rank tracker.
    'rank_tracker' => env('FEATURE_RANK_TRACKER', false),

    // Phase 11: AI visibility (distinct from Copilot).
    'ai_visibility' => env('FEATURE_AI_VISIBILITY', false),

    // Phase 12: uptime monitors / incidents.
    'uptime' => env('FEATURE_UPTIME', false),

];
