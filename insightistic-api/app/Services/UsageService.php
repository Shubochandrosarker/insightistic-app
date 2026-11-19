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
    public function connectedSiteUsage(Organization $org): array
    {
        return [
            'used' => $org->sites()->withoutGlobalScope('organization')->count(),
            'limit' => max(1, $this->limit($org, 'connected_websites')),
        ];
    }

    public function insightLimit(Organization $org): int
    {
        return (int) ($this->resolved($org)['limits']['standard_ai_requests_monthly'] ?? $org->plan?->ai_insight_limit ?? 0);
    }

    public function reportLimit(Organization $org): int
    {
        return (int) ($this->resolved($org)['limits']['client_ready_reports_monthly'] ?? $org->plan?->report_limit ?? 0);
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

    /** Maximum number of active recurring report schedules for the organization. */
    public function scheduledReportLimit(Organization $org): int
    {
        $resolved = $this->resolved($org);
        if (array_key_exists('scheduled_report_limit', $resolved['limits'])) {
            return (int) $resolved['limits']['scheduled_report_limit'];
        }

        // Preserve the legacy boolean entitlement for plans created before the
        // numeric schedule limit was introduced.
        return ((int) ($resolved['limits']['scheduled_reports'] ?? 0)) > 0 ? 4 : 0;
    }

    public function scheduledReportsUsed(Organization $org, ?int $exceptId = null): int
    {
        $query = \App\Models\ReportSchedule::withoutGlobalScope('organization')
            ->where('is_active', true)
            ->whereHas('site', fn ($sites) => $sites->withoutGlobalScope('organization')->where('organization_id', $org->id));

        if ($exceptId !== null) {
            $query->where('id', '<>', $exceptId);
        }

        return (int) $query->count();
    }

    // ------------------------------------------------------------------
    // Knowledge base + Copilot entitlements (spec Phase 8). Every limit is
    // resolved from plans.limits_json (Plan::limit) and enforced HERE, on
    // the server — never in the frontend.
    // ------------------------------------------------------------------

    /** Resolve a limits_json entitlement for an org (code defaults backstop). */
    public function limit(Organization $org, string $key): int
    {
        $limits = $this->resolved($org)['limits'];
        $aliases = [
            'connected_websites'       => 'sites',
            'team_seats'               => 'users',
            'http_crawl_pages_monthly' => 'crawl_pages_monthly',
        ];
        $legacyColumns = [
            'connected_websites' => 'site_limit',
            'team_seats'         => 'user_limit',
        ];

        if (array_key_exists($key, $limits)) {
            return (int) $limits[$key];
        }

        $legacyKey = $aliases[$key] ?? null;
        if ($legacyKey !== null && array_key_exists($legacyKey, $limits)) {
            return (int) $limits[$legacyKey];
        }

        $plan = $org->plan;
        if ($plan) {
            $value = $plan->limit($key);
            if ($value <= 0 && $legacyKey !== null) {
                $value = $plan->limit($legacyKey);
            }
            if ($value <= 0 && isset($legacyColumns[$key])) {
                $value = (int) $plan->{$legacyColumns[$key]};
            }

            return (int) $value;
        }

        return (int) (\App\Models\Plan::LIMIT_DEFAULTS[$key] ?? 0);
    }

    private function resolved(Organization $org): array
    {
        return app(\App\Domain\Billing\EntitlementResolver::class)->resolve($org);
    }

    /**
     * Structured 402 payload the SPA turns into an upgrade prompt. Shared by
     * every knowledge/AI limit denial so clients get one consistent shape.
     */
    public function limitError(Organization $org, string $key, int $used): array
    {
        return [
            'code'    => 'limit_reached',
            'limit'   => ['key' => $key, 'max' => $this->limit($org, $key), 'used' => $used],
            'message' => 'Your plan limit for ' . str_replace('_', ' ', $key) . ' has been reached. Upgrade to continue.',
        ];
    }

    public function knowledgeBasesUsed(Organization $org): int
    {
        return \App\Models\KnowledgeBase::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->count();
    }

    public function knowledgeSourcesUsed(Organization $org): int
    {
        return \App\Models\KnowledgeSource::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)
            ->where('status', '!=', 'disabled')
            ->count();
    }

    public function knowledgeStorageBytesUsed(Organization $org): int
    {
        return (int) \App\Models\KnowledgeSource::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->sum('size_bytes');
    }

    public function indexedChunksUsed(Organization $org): int
    {
        return \App\Models\KnowledgeChunk::withoutGlobalScope('organization')
            ->where('organization_id', $org->id)->count();
    }

    public function canCreateKnowledgeBase(Organization $org): bool
    {
        return $this->knowledgeBasesUsed($org) < $this->limit($org, 'knowledge_bases');
    }

    public function canAddKnowledgeSource(Organization $org, int $additionalBytes = 0): bool
    {
        if ($this->knowledgeSourcesUsed($org) >= $this->limit($org, 'knowledge_sources')) {
            return false;
        }

        $maxBytes = $this->limit($org, 'storage_mb') * 1024 * 1024;

        return $this->knowledgeStorageBytesUsed($org) + $additionalBytes <= $maxBytes;
    }

    public function canSendAiMessage(Organization $org): bool
    {
        return $this->counter($org)->ai_messages_used < $this->limit($org, 'monthly_ai_messages');
    }

    public function recordAiMessage(Organization $org): void
    {
        $this->counter($org)->increment('ai_messages_used');
    }

    public function canEmbed(Organization $org, int $count = 1): bool
    {
        return $this->counter($org)->embeddings_used + $count <= $this->limit($org, 'monthly_embeddings');
    }

    public function recordEmbeddings(Organization $org, int $count): void
    {
        $this->counter($org)->increment('embeddings_used', $count);
    }

    /** Everything the usage meters UI needs, in one payload. */
    public function knowledgeUsageSummary(Organization $org): array
    {
        $counter = $this->counter($org);

        return [
            'knowledge_bases'     => ['used' => $this->knowledgeBasesUsed($org), 'limit' => $this->limit($org, 'knowledge_bases')],
            'knowledge_sources'   => ['used' => $this->knowledgeSourcesUsed($org), 'limit' => $this->limit($org, 'knowledge_sources')],
            'storage_mb'          => ['used' => (int) round($this->knowledgeStorageBytesUsed($org) / 1048576), 'limit' => $this->limit($org, 'storage_mb')],
            'indexed_chunks'      => ['used' => $this->indexedChunksUsed($org), 'limit' => $this->limit($org, 'indexed_chunks')],
            'monthly_embeddings'  => ['used' => (int) $counter->embeddings_used, 'limit' => $this->limit($org, 'monthly_embeddings')],
            'monthly_ai_messages' => ['used' => (int) $counter->ai_messages_used, 'limit' => $this->limit($org, 'monthly_ai_messages')],
        ];
    }
}
