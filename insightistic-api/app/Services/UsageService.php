<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\UsageCounter;

/**
 * Enforces and records monthly plan limits (spec §6 / usage_counters).
 * Controllers call can*() before generating and record*() after success.
 */
class UsageService
{
    public function insightLimit(Organization $org): int
    {
        return (int) ($org->plan?->ai_insight_limit ?? 0);
    }

    public function reportLimit(Organization $org): int
    {
        return (int) ($org->plan?->report_limit ?? 0);
    }

    public function counter(Organization $org): UsageCounter
    {
        return UsageCounter::currentFor($org);
    }

    public function canGenerateInsight(Organization $org): bool
    {
        return $this->counter($org)->ai_insights_used < $this->insightLimit($org);
    }

    public function canGenerateReport(Organization $org): bool
    {
        return $this->counter($org)->reports_generated < $this->reportLimit($org);
    }

    public function recordInsight(Organization $org): void
    {
        $this->counter($org)->increment('ai_insights_used');
    }

    public function recordReport(Organization $org): void
    {
        $this->counter($org)->increment('reports_generated');
    }

    public function insightRemaining(Organization $org): int
    {
        return max(0, $this->insightLimit($org) - $this->counter($org)->ai_insights_used);
    }

    public function reportRemaining(Organization $org): int
    {
        return max(0, $this->reportLimit($org) - $this->counter($org)->reports_generated);
    }
}
