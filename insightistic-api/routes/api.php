<?php

use App\Http\Controllers\Api\Admin\AppSumoLicenseController as AdminAppSumoLicenseController;
use App\Http\Controllers\Api\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Api\Admin\LicenseController as AdminLicenseController;
use App\Http\Controllers\Api\Admin\ManagedLicenseController as AdminManagedLicenseController;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\ObservabilityController as AdminObservabilityController;
use App\Http\Controllers\Api\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\Admin\BillingController as AdminBillingController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Api\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\SyncLogController as AdminSyncLogController;
use App\Http\Controllers\Api\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AppSumoOAuthController;
use App\Http\Controllers\Api\AppSumoWebhookController;
use App\Http\Controllers\Api\AppSumoClaimController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AnalyticsQueryController;
use App\Http\Controllers\Api\ActionProposalController;
use App\Http\Controllers\Api\AgencyController;
use App\Http\Controllers\Api\AnomalyController;
use App\Http\Controllers\Api\AskInsightisticController;
use App\Http\Controllers\Api\AutomationRuleController;
use App\Http\Controllers\Api\CloudflareController;
use App\Http\Controllers\Api\EvidenceController;
use App\Http\Controllers\Api\InvestigationController;
use App\Http\Controllers\Api\OutcomeVerificationController;
use App\Http\Controllers\Api\SavedInsightController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BrandSettingsController;
use App\Http\Controllers\Api\Connector\HandshakeController;
use App\Http\Controllers\Api\Connector\PingController;
use App\Http\Controllers\Api\Connector\SyncController;
use App\Http\Controllers\Api\Connector\SyncV2Controller;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\IntelligenceController;
use App\Http\Controllers\Api\IntegrationsController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\OnboardingController;
use App\Http\Controllers\Api\MetricRegistryController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PaddleWebhookController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\PluginAiController;
use App\Http\Controllers\Api\PluginCloudflareController;
use App\Http\Controllers\Api\PluginLicenseController;
use App\Http\Controllers\Api\PrivacyController;
use App\Http\Controllers\Api\PublicPlanController;
use App\Http\Controllers\Api\RankController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportScheduleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\VersionController;
use App\Http\Controllers\Api\TrafficController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Insightistic API
|--------------------------------------------------------------------------
| Implemented now: auth, organization, sites, connector handshake.
| Future endpoints are listed as commented stubs so the full surface is
| visible — uncomment as each week's controller lands.
*/

// ---- Public plan manifest (spec §13.7) — no auth, cacheable. The single
// source the marketing site's pricing page and any other public surface
// should read from, instead of hardcoding plan copy. ------------------------
Route::get('public/plans', [PublicPlanController::class, 'index'])->middleware('throttle:public');
Route::get('version', VersionController::class)->middleware('throttle:public');

// ---- Public auth (throttle:auth = brute-force protection) -----------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

    // Social login (Google / Microsoft / GitHub). Buttons appear in the SPA
    // only for providers returned by the `oauth/providers` endpoint.
    // Redirect/callback are credential endpoints — brute-force throttled.
    Route::get('oauth/providers', [OAuthController::class, 'providers'])->middleware('throttle:public');
    Route::get('oauth/{provider}/redirect', [OAuthController::class, 'redirect'])->middleware('throttle:auth');
    Route::get('oauth/{provider}/callback', [OAuthController::class, 'callback'])->middleware('throttle:auth');

    // Second step of an MFA login. Public because the caller has no token
    // yet — the single-use challenge minted by login() is what identifies
    // the user, and it is burned on first attempt so it cannot be used to
    // brute-force codes. Throttled like every other credential endpoint.
    Route::post('mfa/challenge', [AuthController::class, 'mfaChallenge'])->middleware('throttle:auth');
});

// ---- Data-source OAuth callback (spec Phase 3.2) --------------------------
// Public because Google redirects the *browser* here, so no bearer token is
// present. Safe because the single-use `state` nonce — minted server-side in
// authorizeUrl() — is what identifies the site, source and user; an
// unrecognised state is rejected outright. Throttled like any credential
// endpoint. Not under /auth: this grants a data scope, never a login.
Route::get('integrations/google/callback', [IntegrationsController::class, 'googleCallback'])
    ->middleware('throttle:auth');

// ---- Authenticated identity (no organization required) --------------------
// `me`/`logout` only need to know who the token belongs to, not which org is
// active — gating them behind `tenant` would 403 any authenticated user with
// zero organizations (e.g. a manually-created super admin, or a team member
// just removed from their only org), logging them out on every page refresh.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

    // Active session/device management (security hardening).
    Route::get('auth/sessions', [AuthController::class, 'sessions']);
    Route::delete('auth/sessions/{tokenId}', [AuthController::class, 'revokeSession']);
    Route::post('auth/sessions/revoke-others', [AuthController::class, 'revokeOtherSessions']);

    // Two-factor authentication (spec Phase 1.4). Reading status is free;
    // every state change requires a recent password confirmation, so a live
    // stolen session cannot swap the second factor for the attacker's own.
    Route::get('auth/mfa', [MfaController::class, 'status']);
    Route::post('auth/reauthenticate', [MfaController::class, 'reauthenticate'])->middleware('throttle:auth');
    Route::middleware('reauth')->group(function () {
        Route::post('auth/mfa/setup', [MfaController::class, 'setup']);
        Route::post('auth/mfa/confirm', [MfaController::class, 'confirm']);
        Route::post('auth/mfa/recovery-codes', [MfaController::class, 'regenerateRecoveryCodes']);
        Route::delete('auth/mfa', [MfaController::class, 'disable']);
    });

    // Single-use super-admin bootstrap token redemption (issued via
    // `php artisan insightistic:super-admin:issue-token`). Throttled hard:
    // failed attempts burn nothing but time.
    Route::post('auth/super-admin/redeem', [\App\Http\Controllers\Api\SuperAdminBootstrapController::class, 'redeem'])
        ->middleware('throttle:auth');

    // The "clear exit" from impersonation (spec §13.9) — called by the
    // impersonated session's own token, so it can't sit behind `tenant` or
    // `super_admin` either.
    Route::post('impersonation/end', [ImpersonationController::class, 'end']);
});

// ---- Authenticated app (user JWT/Sanctum + tenant resolution) -------------
// restrict.impersonation is a no-op for every normal session — it only acts
// when the current token carries the `impersonated` ability (spec §13.9).
Route::middleware(['auth:sanctum', 'tenant', 'restrict.impersonation'])->group(function () {
    Route::get('organizations/current', [OrganizationController::class, 'current']);
    Route::patch('organizations/current', [OrganizationController::class, 'update']);

    // Semantic Metric Registry (spec Phase 1) — canonical metric metadata,
    // not tenant data, so no site scope. Powers the dashboard/Copilot/report
    // surfaces that need a metric's label/unit/formula/caveats.
    Route::get('metrics', [MetricRegistryController::class, 'index']);
    Route::get('metrics/{metric}', [MetricRegistryController::class, 'show']);

    // Agency Intelligence (spec Phase 2 / P10) — cross-site rollup for the
    // caller's own organization. No {site} segment on purpose; see
    // AgencyController's docblock.
    Route::get('agency/overview', [AgencyController::class, 'overview']);

    // Agency portfolio (Phase 13, doc 20) — per-site health/uptime/search
    // rows over the caller's own organization, with the optional client
    // grouping. Reads mirror agency/overview access (any org member);
    // client CRUD and site assignment are owner/admin writes.
    Route::get('agency/portfolio', [AgencyController::class, 'portfolio']);
    Route::get('agency/clients', [AgencyController::class, 'index']);
    Route::post('agency/clients', [AgencyController::class, 'store'])->middleware('role:owner,admin');
    Route::patch('agency/clients/{client}', [AgencyController::class, 'update'])->middleware('role:owner,admin');
    Route::delete('agency/clients/{client}', [AgencyController::class, 'destroy'])->middleware('role:owner,admin');
    Route::post('agency/clients/{client}/sites', [AgencyController::class, 'assignSites'])->middleware('role:owner,admin');

    // Team management (Week 5) — owner/admin can view & invite; owner-only mutations.
    Route::get('organizations/users', [TeamController::class, 'index'])->middleware('role:owner,admin');
    Route::post('organizations/users/invite', [TeamController::class, 'invite'])->middleware('role:owner,admin');
    Route::patch('organizations/users/{user}/role', [TeamController::class, 'updateRole'])->middleware('role:owner');
    Route::delete('organizations/users/{user}', [TeamController::class, 'remove'])->middleware('role:owner');

    // Privacy and compliance (spec Phase 10). Owner-only, and every
    // mutation is behind a fresh password confirmation — an export is a
    // portable copy of the whole tenant and deletion is irreversible.
    Route::get('privacy/overview', [PrivacyController::class, 'overview'])->middleware('role:owner,admin');
    Route::middleware(['role:owner', 'reauth'])->group(function () {
        Route::post('privacy/exports', [PrivacyController::class, 'requestExport']);
        Route::get('privacy/exports/{export}/download', [PrivacyController::class, 'downloadExport']);
        Route::post('privacy/deletion', [PrivacyController::class, 'requestDeletion']);
        Route::delete('privacy/deletion', [PrivacyController::class, 'cancelDeletion']);
        Route::patch('privacy/ai-processing', [PrivacyController::class, 'updateAiProcessing']);
    });

    // Billing (Week 5) — owner manages payment; owner/admin can view status.
    Route::get('billing/subscription', [BillingController::class, 'subscription'])->middleware('role:owner,admin');
    Route::get('billing/credits', [BillingController::class, 'credits'])->middleware('role:owner,admin');
    Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware(['role:owner', 'reauth']);
    Route::post('billing/portal', [BillingController::class, 'portal'])->middleware('role:owner');
    Route::post('billing/cancel', [BillingController::class, 'cancel'])->middleware(['role:owner', 'reauth']);
    Route::post('billing/reactivate', [BillingController::class, 'reactivate'])->middleware('role:owner');

    Route::post('billing/start-trial', [BillingController::class, 'startTrial'])->middleware('role:owner');

    // Stripe checkout (owner, no reauth: nothing is charged until the
    // customer confirms on Stripe's page).
    Route::post('billing/stripe/checkout', [BillingController::class, 'checkout'])->middleware('role:owner');
    Route::get('billing/providers', [BillingController::class, 'providers'])->middleware('role:owner,admin');
    Route::get('billing/summary', [BillingController::class, 'summary'])->middleware('role:owner,admin');

    // Credit packs bought with Stripe (one-time). Owner-only like the other
    // checkout creators; credits are granted by the webhook, never by the
    // response. The balance view is readable by admins, like billing/credits.
    Route::post('billing/credits/checkout', [BillingController::class, 'creditPackCheckout'])->middleware('role:owner');
    Route::get('billing/credits/balance', [BillingController::class, 'creditBalance'])->middleware('role:owner,admin');

    // Licenses tab (spec §2). Raw keys only ever appear in regenerate/register responses.
    Route::get('licenses', [LicenseController::class, 'index']);
    Route::post('licenses/{license}/regenerate', [LicenseController::class, 'regenerate'])->middleware(['role:owner', 'reauth']);
    Route::get('licenses/{license}/activations', [LicenseController::class, 'activations']);
    Route::get('licenses/{license}/events', [LicenseController::class, 'events']);
    Route::delete('activations/{activation}', [LicenseController::class, 'deactivateActivation'])->middleware('role:owner,admin');

    // Notifications / conversion prompts.
    // Guided setup (spec §9). Progress is derived from real state, so the
    // checklist resumes correctly on any device with nothing stored.
    Route::get('onboarding', [OnboardingController::class, 'index']);
    Route::post('onboarding/dismiss', [OnboardingController::class, 'dismiss']);
    Route::delete('onboarding/dismiss', [OnboardingController::class, 'restore']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{notification}', [NotificationController::class, 'update']);

    // White-label (Week 5) — read for owner/admin; writes owner-only + plan-gated.
    Route::get('brand-settings', [BrandSettingsController::class, 'show'])->middleware('role:owner,admin');
    Route::patch('brand-settings', [BrandSettingsController::class, 'update'])->middleware('role:owner');
    Route::post('brand-settings/logo', [BrandSettingsController::class, 'uploadLogo'])->middleware('role:owner');

    Route::get('sites', [SiteController::class, 'index']);
    Route::post('sites', [SiteController::class, 'store']);

    // Product funnel events from the SPA (spec §33). Strict taxonomy +
    // props whitelist enforced server-side (AttributionService::event).
    Route::post('events', function (\Illuminate\Http\Request $request) {
        $data = $request->validate([
            'event' => ['required', 'string', 'max:64'],
            'props' => ['nullable', 'array'],
        ]);

        $recorded = app(\App\Services\Analytics\AttributionService::class)->event(
            $data['event'],
            app(\App\Support\Tenancy::class)->organization(),
            $request->user(),
            $data['props'] ?? [],
        );

        return response()->json(['recorded' => $recorded !== null]);
    })->middleware('throttle:auth');

    // Org-level provider accounts (spec §5/§6): ONE Google consent for
    // every site in the organization. Not under site.access — these are
    // organization-scoped, not site-scoped.
    Route::get('integrations/google/account', [IntegrationsController::class, 'googleAccountStatus']);
    Route::get('integrations/google/ga4/properties', [IntegrationsController::class, 'ga4Properties']);
    Route::middleware('role:owner,admin')->group(function () {
        Route::post('integrations/google/account/authorize-url', [IntegrationsController::class, 'googleAccountAuthorizeUrl']);
        Route::delete('integrations/google/account', [IntegrationsController::class, 'googleAccountDisconnect']);
        Route::get('integrations/google/gsc/properties', [IntegrationsController::class, 'gscProperties']);
        Route::post('integrations/google/gsc/import', [IntegrationsController::class, 'gscImport']);
    });

    Route::middleware('site.access')->group(function () {
        Route::get('sites/{site}', [SiteController::class, 'show']);
        Route::get('sites/{site}/health', [SiteController::class, 'health']);
        Route::get('sites/{site}/orders', [AnalyticsController::class, 'ordersList']);
        Route::post('sites/{site}/regenerate-api-key', [SiteController::class, 'regenerateApiKey'])
            ->middleware('role:owner,admin');

        // Analytics (Week 3) — read from precomputed snapshots + live tables.
        Route::get('sites/{site}/analytics/overview', [AnalyticsController::class, 'overview']);
        Route::get('sites/{site}/analytics/revenue', [AnalyticsController::class, 'revenue']);
        Route::get('sites/{site}/analytics/orders', [AnalyticsController::class, 'orders']);
        Route::get('sites/{site}/analytics/products', [AnalyticsController::class, 'products']);
        Route::get('sites/{site}/analytics/customers', [AnalyticsController::class, 'customers']);
        Route::get('sites/{site}/analytics/refunds', [AnalyticsController::class, 'refunds']);
        Route::get('sites/{site}/analytics/compare', [AnalyticsController::class, 'compare']);

        // Safe WooCommerce Query Engine (spec Phase 2 / P1) — executes an
        // already-validated semantic plan. POST because a plan is a
        // structured request body, not just a handful of query params.
        Route::post('sites/{site}/analytics/query', [AnalyticsQueryController::class, 'query'])
            ->middleware('throttle:query-engine');

        // Analytical Evidence Ledger (spec Phase 2 / P2) — the "Evidence
        // drawer": what ran, which metric versions, freshness, outcome.
        Route::get('sites/{site}/evidence', [EvidenceController::class, 'index']);
        Route::get('sites/{site}/evidence/{run}', [EvidenceController::class, 'show']);

        // Ask Insightistic V2 (spec Phase 2 / P3) — free-text question in,
        // cited/confidence-scored answer out. Runs through the same Safe
        // Query Engine + Evidence Ledger pipeline as analytics/query, so it
        // shares that route's throttle.
        Route::post('sites/{site}/ask', [AskInsightisticController::class, 'ask'])
            ->middleware(['throttle:query-engine', 'ai.available']);

        // Saved Insights (spec Phase 2 / P5) — a named, reusable plan a
        // user can re-run on demand. "run" shares analytics/query's
        // throttle since it executes the same engine.
        Route::get('sites/{site}/saved-insights', [SavedInsightController::class, 'index']);
        Route::post('sites/{site}/saved-insights', [SavedInsightController::class, 'store']);
        Route::get('sites/{site}/saved-insights/{savedInsight}', [SavedInsightController::class, 'show']);
        Route::patch('sites/{site}/saved-insights/{savedInsight}', [SavedInsightController::class, 'update']);
        Route::delete('sites/{site}/saved-insights/{savedInsight}', [SavedInsightController::class, 'destroy']);
        Route::post('sites/{site}/saved-insights/{savedInsight}/run', [SavedInsightController::class, 'run'])
            ->middleware('throttle:query-engine');

        // Automation Studio (spec Phase 2 / P6) — trigger -> action rules
        // an org admin configures once. Actions execute automatically once
        // approved by configuration; contrast with P9's ActionProposal,
        // which always requires an explicit human approval per action.
        Route::get('sites/{site}/automation-rules', [AutomationRuleController::class, 'index']);
        // Rule-builder catalog (triggers/actions the dispatcher really
        // supports) — read-only, any site-access role. MUST be matched
        // before the {automationRule} show route below, or "catalog" would
        // be swallowed as a rule id.
        Route::get('sites/{site}/automation-rules/catalog', [AutomationRuleController::class, 'catalog']);
        Route::get('sites/{site}/automation-rules/{automationRule}', [AutomationRuleController::class, 'show']);
        Route::get('sites/{site}/automation-rules/{automationRule}/runs', [AutomationRuleController::class, 'runs']);
        // Configuring or firing a rule executes real side effects (webhooks,
        // emails) — a client_viewer (read-only stakeholder access) must not
        // be able to create/edit/fire one, only see that it exists.
        Route::middleware('role:owner,admin,analyst')->group(function () {
            Route::post('sites/{site}/automation-rules', [AutomationRuleController::class, 'store'])->middleware('ai.available');
            Route::patch('sites/{site}/automation-rules/{automationRule}', [AutomationRuleController::class, 'update'])->middleware('ai.available');
            Route::delete('sites/{site}/automation-rules/{automationRule}', [AutomationRuleController::class, 'destroy'])->middleware('ai.available');
            Route::post('sites/{site}/automation-rules/{automationRule}/run', [AutomationRuleController::class, 'run'])->middleware('ai.available');
        });

        // Anomaly Engine (spec Phase 2 / P7) — median/MAD detection across
        // the small fixed metric set AnomalyDetector::supportedMetrics()
        // declares.
        Route::get('sites/{site}/anomalies', [AnomalyController::class, 'index']);
        Route::post('sites/{site}/anomalies/scan', [AnomalyController::class, 'scan']);
        Route::post('sites/{site}/anomalies/{anomaly}/acknowledge', [AnomalyController::class, 'acknowledge'])
            ->middleware('role:owner,admin,analyst');

        // WPAgentistic Investigations (spec Phase 2 / P8) — read-only,
        // multi-metric diagnostic runs. Shares analytics/query's throttle
        // since each investigation runs the same query engine, once per
        // requested metric.
        Route::get('sites/{site}/investigations', [InvestigationController::class, 'index']);
        Route::post('sites/{site}/investigations', [InvestigationController::class, 'store'])
            ->middleware(['throttle:query-engine', 'ai.available']);
        Route::get('sites/{site}/investigations/{investigation}', [InvestigationController::class, 'show']);

        // Bridgistic Approved Actions (spec Phase 2 / P9) — a proposed
        // action that ALWAYS requires an explicit human approval before it
        // ever executes. No auto-approval route exists.
        Route::get('sites/{site}/action-proposals', [ActionProposalController::class, 'index']);
        Route::post('sites/{site}/action-proposals', [ActionProposalController::class, 'store']);
        Route::get('sites/{site}/action-proposals/{actionProposal}', [ActionProposalController::class, 'show']);
        // approve() is the one method in the whole app that actually executes
        // a proposed action (ActionProposalService::approve -> ActionExecutor).
        // The "explicit human approval" invariant means a trusted human, not
        // any authenticated user with view access to the site.
        Route::middleware('role:owner,admin,analyst')->group(function () {
            Route::post('sites/{site}/action-proposals/{actionProposal}/approve', [ActionProposalController::class, 'approve']);
            Route::post('sites/{site}/action-proposals/{actionProposal}/reject', [ActionProposalController::class, 'reject']);
        });

        // Outcome Verification (spec Phase 2 / P11) — closes the loop P9
        // opens: did an executed action's watched metric actually improve?
        // No store() route — rows are created automatically by
        // ActionProposalService::approve().
        Route::get('sites/{site}/outcome-verifications', [OutcomeVerificationController::class, 'index']);
        Route::get('sites/{site}/outcome-verifications/{outcomeVerification}', [OutcomeVerificationController::class, 'show']);
        Route::post('sites/{site}/outcome-verifications/{outcomeVerification}/verify', [OutcomeVerificationController::class, 'verify']);

        // Traffic / SEO / Performance (Milestone 4). Read the tables Milestone
        // 3 shipped; return honest empty states until connector v2 ingests.
        Route::get('sites/{site}/analytics/traffic/overview', [TrafficController::class, 'overview']);
        Route::get('sites/{site}/analytics/traffic/dimensions/{type}', [TrafficController::class, 'dimensions']);

        Route::get('sites/{site}/analytics/seo/overview', [SeoController::class, 'overview']);
        Route::get('sites/{site}/analytics/seo/queries', [SeoController::class, 'queries']);
        Route::get('sites/{site}/analytics/seo/pages', [SeoController::class, 'pages']);
        // Bing weekly query buckets (Phase 6, roadmap 11-BING-WEBMASTER-INDEXNOW).
        Route::get('sites/{site}/analytics/seo/bing', [SeoController::class, 'bing']);

        // Search Intelligence (Phase 5, roadmap 10-GOOGLE-SEARCH-CONSOLE).
        // Reads the full daily GSC fact tables with window comparison and the
        // opportunity engine — deeper than the snapshot-backed SEO routes
        // above. Reads are open to anyone with site access; the backfill is
        // a queued, quota-consuming heavy action, so owner/admin like every
        // other sync/run endpoint in this file.
        Route::get('sites/{site}/search/queries', [SearchController::class, 'queries']);
        Route::get('sites/{site}/search/pages', [SearchController::class, 'pages']);
        Route::get('sites/{site}/search/opportunities', [SearchController::class, 'opportunities']);
        Route::get('sites/{site}/search/backfill-status', [SearchController::class, 'status']);
        Route::post('sites/{site}/search/backfill', [SearchController::class, 'backfill'])
            ->middleware('role:owner,admin');

        // Site crawler (Phase 7, doc 12). Reads over runs/pages/links/issues
        // are open to anyone with site access, like every other analytics
        // surface. Starting a crawl and triaging issues are owner/admin:
        // a crawl consumes the plan's monthly page budget and puts load on
        // the target site, matching every other queued heavy action.
        Route::get('sites/{site}/crawl/runs', [\App\Http\Controllers\Api\CrawlController::class, 'runs']);
        Route::get('sites/{site}/crawl/runs/{run}', [\App\Http\Controllers\Api\CrawlController::class, 'runShow']);
        Route::get('sites/{site}/crawl/pages', [\App\Http\Controllers\Api\CrawlController::class, 'pages']);
        Route::get('sites/{site}/crawl/links', [\App\Http\Controllers\Api\CrawlController::class, 'links']);
        Route::get('sites/{site}/crawl/issues', [\App\Http\Controllers\Api\CrawlController::class, 'issues']);
        Route::get('sites/{site}/crawl/changes', [\App\Http\Controllers\Api\CrawlController::class, 'changes']);
        Route::get('sites/{site}/crawl/health', [\App\Http\Controllers\Api\CrawlController::class, 'health']);
        Route::middleware('role:owner,admin')->group(function () {
            Route::patch('sites/{site}/crawl/settings', [\App\Http\Controllers\Api\CrawlController::class, 'settings']);
            Route::post('sites/{site}/crawl/run', [\App\Http\Controllers\Api\CrawlController::class, 'run']);
            Route::post('sites/{site}/crawl/issues/{issue}/status', [\App\Http\Controllers\Api\CrawlController::class, 'issueStatus']);
        });

        // Rank tracker (Phase 10, doc 13). Reads over keywords/observations/
        // suggestions are open to anyone with site access, like every other
        // analytics surface. Tracking keywords and queueing checks are
        // owner/admin: keywords consume the plan's tracked_keywords quota
        // and checks burn paid SERP-provider cost, matching every other
        // queued heavy action. GSC average position and tracker position
        // stay separate products (doc 13) — the overview payload labels
        // them distinctly (impressions_28d vs latest/previous/best).
        Route::get('sites/{site}/rank', [RankController::class, 'index']);
        Route::get('sites/{site}/rank/suggestions', [RankController::class, 'suggestions']);
        Route::get('sites/{site}/rank/keywords/{keyword}/history', [RankController::class, 'history']);
        // Latest observation per keyword as CSV — bounded provider-cost
        // export, on the exports limiter like every other CSV.
        Route::get('sites/{site}/rank.csv', [ExportController::class, 'rank'])
            ->middleware('throttle:exports');
        Route::middleware('role:owner,admin')->group(function () {
            Route::post('sites/{site}/rank/keywords', [RankController::class, 'storeKeywords']);
            Route::delete('sites/{site}/rank/keywords/{keyword}', [RankController::class, 'destroyKeyword']);
            Route::post('sites/{site}/rank/keywords/{keyword}/check', [RankController::class, 'checkKeyword']);
            Route::post('sites/{site}/rank/check', [RankController::class, 'checkSite']);
            Route::post('sites/{site}/rank/suggestions/confirm', [RankController::class, 'confirmSuggestions']);
        });

        // Cloudflare Edge Intelligence (Phase 9, roadmap 15). Pure read over
        // the cloudflare_daily/cloudflare_bot_daily tables the nightly sync
        // fills — never a live Cloudflare call — so it's open to anyone with
        // site access like every other analytics surface.
        Route::get('sites/{site}/cloudflare', [CloudflareController::class, 'overview']);

        Route::get('sites/{site}/analytics/performance/history', [PerformanceController::class, 'history']);
        Route::get('sites/{site}/analytics/performance/latest', [PerformanceController::class, 'latest']);
        // Run PageSpeed from the SaaS backend (spec Phase 3.3). Queued, and
        // owner/admin only — each run consumes shared API quota.
        Route::post('sites/{site}/analytics/performance/run', [PerformanceController::class, 'runNow'])
            ->middleware('role:owner,admin');

        // IndexNow (Phase 6, roadmap 11-BING-WEBMASTER-INDEXNOW). The SaaS
        // holds the key and the submission log; the WordPress plugin hosts
        // the {key}.txt file. History/status is open to anyone with site
        // access; submitting burns shared provider quota and regenerating a
        // key invalidates the plugin's key file — so submit is owner/admin
        // and regenerate is owner-only. Submits are synchronous but bounded
        // (max 1000 URLs) and rate-limited via the bounded-heavy-action
        // exports limiter (provider calls, like CSV generation, must not be
        // spammable).
        Route::get('sites/{site}/indexnow', [\App\Http\Controllers\Api\IndexNowController::class, 'index']);
        Route::post('sites/{site}/indexnow/submit', [\App\Http\Controllers\Api\IndexNowController::class, 'submit'])
            ->middleware(['role:owner,admin', 'throttle:exports']);
        Route::post('sites/{site}/indexnow/regenerate-key', [\App\Http\Controllers\Api\IndexNowController::class, 'regenerateKey'])
            ->middleware('role:owner');

        // Email/campaign analytics (spec Phase 3.4). Reading is open to
        // anyone with site access; connecting a provider is owner/admin.
        Route::get('sites/{site}/emails/overview', [EmailController::class, 'overview']);
        Route::get('sites/{site}/emails/campaigns', [EmailController::class, 'campaigns']);
        Route::middleware('role:owner,admin')->group(function () {
            Route::post('sites/{site}/emails/connect', [EmailController::class, 'connect']);
            Route::post('sites/{site}/emails/sync', [EmailController::class, 'sync']);
            Route::delete('sites/{site}/emails/connection', [EmailController::class, 'disconnect']);
        });

        // Data-source health + direct connection workflow (spec Phase 3.2).
        // Reading status is open to anyone with site access; connecting or
        // disconnecting a data source is an owner/admin action.
        Route::get('sites/{site}/integrations', [IntegrationsController::class, 'index']);

        Route::middleware('role:owner,admin')->group(function () {
            Route::post('sites/{site}/integrations/cloudflare/connect', [IntegrationsController::class, 'connectCloudflare']);
            Route::post('sites/{site}/integrations/bing/connect', [IntegrationsController::class, 'connectBing']);
            Route::post('sites/{site}/integrations/{source}/authorize-url', [IntegrationsController::class, 'authorizeUrl']);
            Route::post('sites/{site}/integrations/{source}/attach', [IntegrationsController::class, 'attach']);
            Route::get('sites/{site}/integrations/{source}/resources', [IntegrationsController::class, 'resources']);
            Route::post('sites/{site}/integrations/{source}/select', [IntegrationsController::class, 'select']);
            Route::post('sites/{site}/integrations/{source}/test', [IntegrationsController::class, 'test']);
            Route::post('sites/{site}/integrations/{source}/resync', [IntegrationsController::class, 'resync']);
            Route::delete('sites/{site}/integrations/{source}', [IntegrationsController::class, 'disconnect']);
        });

        // Alerts (Milestone 5, spec §12) — deterministic rules, run on a
        // schedule (routes/console.php) or on demand via scan().
        Route::get('sites/{site}/alerts', [AlertController::class, 'index']);
        Route::post('sites/{site}/alerts/scan', [AlertController::class, 'scan']);
        Route::middleware('role:owner,admin,analyst')->group(function () {
            Route::post('sites/{site}/alerts/{alert}/resolve', [AlertController::class, 'resolve']);
            Route::post('sites/{site}/alerts/{alert}/reopen', [AlertController::class, 'reopen']);
        });

        // AI insights (Week 4)
        Route::get('sites/{site}/insights', [InsightController::class, 'index']);
        Route::post('sites/{site}/insights/generate', [InsightController::class, 'generate'])->middleware('ai.available');
        Route::get('sites/{site}/insights/{insight}', [InsightController::class, 'show']);
        Route::post('sites/{site}/insights/{insight}/mark-read', [InsightController::class, 'markRead']);
        // Lifecycle (spec Phase 6). Saving and dismissing are orthogonal to
        // read/done, so they are separate verbs rather than more statuses.
        Route::post('sites/{site}/insights/{insight}/save', [InsightController::class, 'save']);
        Route::delete('sites/{site}/insights/{insight}/save', [InsightController::class, 'unsave']);
        Route::post('sites/{site}/insights/{insight}/dismiss', [InsightController::class, 'dismiss']);
        Route::delete('sites/{site}/insights/{insight}/dismiss', [InsightController::class, 'restore']);
        Route::post('sites/{site}/insights/{insight}/action', [InsightController::class, 'action']);

        /*
         * Advanced business intelligence (spec Phase 7). Split per report
         * rather than one payload: cohorts walks the site's whole order
         * history while channels is a single grouped read, and blocking the
         * fast panels on the slow one makes the page feel broken.
         */
        Route::get('sites/{site}/intelligence/rfm', [IntelligenceController::class, 'rfm']);
        Route::get('sites/{site}/intelligence/cohorts', [IntelligenceController::class, 'cohorts']);
        Route::get('sites/{site}/intelligence/lifetime-value', [IntelligenceController::class, 'lifetimeValue']);
        Route::get('sites/{site}/intelligence/affinity', [IntelligenceController::class, 'affinity']);
        Route::get('sites/{site}/intelligence/stock-risk', [IntelligenceController::class, 'stockRisk']);
        Route::get('sites/{site}/intelligence/discounts', [IntelligenceController::class, 'discountImpact']);
        Route::get('sites/{site}/intelligence/declining-products', [IntelligenceController::class, 'decliningProducts']);
        Route::get('sites/{site}/intelligence/cannibalization', [IntelligenceController::class, 'cannibalization']);
        Route::get('sites/{site}/intelligence/ctr-opportunities', [IntelligenceController::class, 'ctrOpportunities']);
        Route::get('sites/{site}/intelligence/content-decay', [IntelligenceController::class, 'contentDecay']);
        Route::get('sites/{site}/intelligence/channels', [IntelligenceController::class, 'channels']);
        Route::get('sites/{site}/intelligence/anomalies', [IntelligenceController::class, 'anomalies']);
        Route::get('sites/{site}/intelligence/performance-correlation', [IntelligenceController::class, 'performanceCorrelation']);

        // Churn risk is the one intelligence report that names individual
        // customers, so it is not part of what a client_viewer is shown.
        Route::middleware('role:owner,admin,analyst')->group(function () {
            Route::get('sites/{site}/intelligence/churn-risk', [IntelligenceController::class, 'churnRisk']);
        });

        // Reports (Week 4)
        Route::get('sites/{site}/reports', [ReportController::class, 'index']);
        Route::post('sites/{site}/reports/generate', [ReportController::class, 'generate'])->middleware('ai.available');

        // Scheduled report delivery (Milestone 5, spec §12). A schedule sends
        // data to an email address on a recurring basis, so configuring one
        // is owner/admin, matching emails/integrations connect above.
        Route::get('sites/{site}/report-schedules', [ReportScheduleController::class, 'index']);
        Route::middleware('role:owner,admin')->group(function () {
            Route::post('sites/{site}/report-schedules', [ReportScheduleController::class, 'store']);
            Route::patch('sites/{site}/report-schedules/{schedule}', [ReportScheduleController::class, 'update']);
            Route::delete('sites/{site}/report-schedules/{schedule}', [ReportScheduleController::class, 'destroy']);
        });

        // Bounded CSV exports (Milestone 5, spec §12).
        Route::middleware('throttle:exports')->group(function () {
            Route::get('sites/{site}/exports/traffic.csv', [ExportController::class, 'traffic']);
            Route::get('sites/{site}/exports/seo.csv', [ExportController::class, 'seo']);
            Route::get('sites/{site}/exports/crawl.csv', [ExportController::class, 'crawl']);
            Route::get('sites/{site}/exports/performance.csv', [ExportController::class, 'performance']);
            Route::get('sites/{site}/exports/commerce.csv', [ExportController::class, 'commerce']);
            // Search Intelligence export (Phase 5) — ?type= picks the report.
            Route::get('sites/{site}/search/export.csv', [ExportController::class, 'search']);
        });

        // AI Visibility (Phase 11, docs/roadmap-2026/14-AI-VISIBILITY.md).
        // Reads over stored observations (runs/mentions/citations) are open
        // to anyone with site access, like every other analytics surface.
        // Tracking prompts/competitors and queueing a check are owner/admin:
        // every prompt run costs real provider spend and burns the plan's
        // ai_prompt_runs_monthly quota, matching every other queued heavy
        // action (quota guard lives in the controller + job).
        Route::get('sites/{site}/ai-visibility', [\App\Http\Controllers\Api\AiVisibilityController::class, 'overview']);
        Route::get('sites/{site}/ai-visibility/prompts', [\App\Http\Controllers\Api\AiVisibilityController::class, 'prompts']);
        Route::get('sites/{site}/ai-visibility/mentions', [\App\Http\Controllers\Api\AiVisibilityController::class, 'mentions']);
        Route::get('sites/{site}/ai-visibility/competitors', [\App\Http\Controllers\Api\AiVisibilityController::class, 'competitors']);
        Route::middleware('role:owner,admin')->group(function () {
            Route::post('sites/{site}/ai-visibility/prompts', [\App\Http\Controllers\Api\AiVisibilityController::class, 'storePrompt'])->middleware('ai.available');
            Route::delete('sites/{site}/ai-visibility/prompts/{prompt}', [\App\Http\Controllers\Api\AiVisibilityController::class, 'destroyPrompt'])->middleware('ai.available');
            Route::match(['get', 'post'], 'sites/{site}/ai-visibility/prompts/{prompt}/check', [\App\Http\Controllers\Api\AiVisibilityController::class, 'checkPrompt'])->middleware('ai.available');
            Route::post('sites/{site}/ai-visibility/competitors', [\App\Http\Controllers\Api\AiVisibilityController::class, 'storeCompetitor'])->middleware('ai.available');
            Route::delete('sites/{site}/ai-visibility/competitors/{competitor}', [\App\Http\Controllers\Api\AiVisibilityController::class, 'destroyCompetitor'])->middleware('ai.available');
        });
    });

    // Report view + send (not site-scoped in the URL; access checked in controller).
    Route::get('reports/{report}', [ReportController::class, 'show']);
    Route::post('reports/{report}/send-email', [ReportController::class, 'sendEmail']);

    // ---- AI Knowledge Base (Phase 4) — org-scoped via the tenant global
    // scope; reads for working roles, mutations for owner/admin only; every
    // plan limit enforced server-side in the controllers. -------------------
    Route::middleware('role:owner,admin,analyst')->group(function () {
        Route::get('knowledge-bases', [\App\Http\Controllers\Api\KnowledgeBaseController::class, 'index']);
        Route::get('knowledge-bases/{id}', [\App\Http\Controllers\Api\KnowledgeBaseController::class, 'show']);
        Route::get('knowledge-bases/{id}/sources', [\App\Http\Controllers\Api\KnowledgeSourceController::class, 'index']);
        Route::get('knowledge-sources/{id}', [\App\Http\Controllers\Api\KnowledgeSourceController::class, 'show']);
        Route::post('knowledge-bases/{id}/test-retrieval', [\App\Http\Controllers\Api\KnowledgeSearchController::class, 'testRetrieval']);
        Route::get('knowledge/usage', [\App\Http\Controllers\Api\KnowledgeSearchController::class, 'usage']);
    });

    Route::middleware('role:owner,admin')->group(function () {
        Route::post('knowledge-bases', [\App\Http\Controllers\Api\KnowledgeBaseController::class, 'store']);
        Route::patch('knowledge-bases/{id}', [\App\Http\Controllers\Api\KnowledgeBaseController::class, 'update']);
        Route::delete('knowledge-bases/{id}', [\App\Http\Controllers\Api\KnowledgeBaseController::class, 'destroy']);

        Route::post('knowledge-bases/{id}/sources', [\App\Http\Controllers\Api\KnowledgeSourceController::class, 'store'])
            ->middleware('throttle:knowledge-upload');
        Route::post('knowledge-sources/{id}/retry', [\App\Http\Controllers\Api\KnowledgeSourceController::class, 'retry'])
            ->middleware('throttle:knowledge-crawl');
        Route::post('knowledge-sources/{id}/reindex', [\App\Http\Controllers\Api\KnowledgeSourceController::class, 'reindex'])
            ->middleware('throttle:knowledge-crawl');
        Route::post('knowledge-sources/{id}/disable', [\App\Http\Controllers\Api\KnowledgeSourceController::class, 'disable']);
        Route::delete('knowledge-sources/{id}', [\App\Http\Controllers\Api\KnowledgeSourceController::class, 'destroy']);
    });

    // ---- AI Copilot (Phase 5) — personal threads within the org. ----------
    Route::get('copilot/agents', [\App\Http\Controllers\Api\CopilotController::class, 'agents']);
    Route::get('copilot/threads', [\App\Http\Controllers\Api\CopilotController::class, 'threads']);
    Route::post('copilot/threads', [\App\Http\Controllers\Api\CopilotController::class, 'createThread']);
    Route::get('copilot/threads/{id}', [\App\Http\Controllers\Api\CopilotController::class, 'showThread']);
    Route::patch('copilot/threads/{id}', [\App\Http\Controllers\Api\CopilotController::class, 'updateThread']);
    Route::delete('copilot/threads/{id}', [\App\Http\Controllers\Api\CopilotController::class, 'deleteThread']);
    Route::post('copilot/threads/{id}/messages', [\App\Http\Controllers\Api\CopilotController::class, 'sendMessage'])
        ->middleware('throttle:ai-chat');
    Route::post('copilot/messages/{id}/feedback', [\App\Http\Controllers\Api\CopilotController::class, 'feedback']);
    Route::get('copilot/suggestions', [\App\Http\Controllers\Api\CopilotController::class, 'suggestions']);
});

// ---- Report downloads (signed, unauthenticated) ----------------------------
// The signature itself is the access control — these are opened as plain
// browser navigations (<a href target=_blank>) from ClientReport::pdf_link/
// html_link, which can't carry a Sanctum bearer token. Deliberately outside
// the auth:sanctum group.
Route::get('reports/{report}/pdf', [ReportController::class, 'downloadPdf'])
    ->name('reports.download.pdf');
Route::get('reports/{report}/html', [ReportController::class, 'downloadHtml'])
    ->name('reports.download.html');

// ---- Super Admin (platform owner; auth + super_admin, NO tenant scope) -----
Route::prefix('admin')->middleware(['auth:sanctum', 'super_admin'])->group(function () {
    Route::get('overview', [AdminOverviewController::class, 'overview']);
    Route::get('system-health', [AdminOverviewController::class, 'systemHealth']);
    Route::get('settings', [AdminOverviewController::class, 'settings']);
    Route::get('ai/configuration', [AdminOverviewController::class, 'aiConfiguration']);
    Route::patch('ai/configuration', [AdminOverviewController::class, 'updateAiConfiguration'])->middleware('reauth');

    /*
     * Observability (spec Phase 2.3). Platform-wide, not tenant-scoped:
     * route latency spans every tenant's traffic and AI spend is aggregate,
     * so surfacing either inside an organization's dashboard would leak the
     * shape of other customers' usage.
     */
    Route::get('observability', [AdminObservabilityController::class, 'overview']);
    Route::get('observability/requests', [AdminObservabilityController::class, 'requests']);
    Route::get('observability/ai', [AdminObservabilityController::class, 'ai']);
    Route::get('observability/queue', [AdminObservabilityController::class, 'queue']);

    Route::get('organizations', [AdminOrganizationController::class, 'index']);
    Route::post('organizations', [AdminOrganizationController::class, 'store']);
    Route::get('organizations/{id}', [AdminOrganizationController::class, 'show']);
    Route::patch('organizations/{id}', [AdminOrganizationController::class, 'update']);
    Route::post('organizations/{id}/members', [AdminOrganizationController::class, 'addMember']);
    Route::patch('organizations/{id}/members/{userId}', [AdminOrganizationController::class, 'updateMember']);
    Route::delete('organizations/{id}/members/{userId}', [AdminOrganizationController::class, 'removeMember']);
    Route::post('organizations/{id}/archive', [AdminOrganizationController::class, 'archive']);
    Route::post('organizations/{id}/restore', [AdminOrganizationController::class, 'restore']);

    Route::get('users', [AdminUserController::class, 'index']);
    Route::post('users', [AdminUserController::class, 'store']);
    Route::get('users/{id}', [AdminUserController::class, 'show']);
    Route::patch('users/{id}', [AdminUserController::class, 'update']);
    // Super-admin promotion/demotion is the highest-privilege change in
    // the platform — always behind a fresh password confirmation.
    Route::post('users/{id}/promote', [AdminUserController::class, 'promote'])->middleware('reauth');
    Route::post('users/{id}/demote', [AdminUserController::class, 'demote'])->middleware('reauth');
    Route::post('users/{id}/archive', [AdminUserController::class, 'archive']);
    Route::post('users/{id}/restore', [AdminUserController::class, 'restore']);

    Route::get('sites', [AdminSiteController::class, 'index']);
    Route::post('sites', [AdminSiteController::class, 'store']);
    Route::get('sites/{id}', [AdminSiteController::class, 'show']);
    Route::patch('sites/{id}', [AdminSiteController::class, 'update']);
    // Rotates the HMAC connector credential pair (Site::issueConnectorToken).
    // The raw token appears ONCE in this response — exactly like the plugin
    // handshake flow — and the old pair stops working immediately.
    Route::post('sites/{id}/regenerate-key', [AdminSiteController::class, 'regenerateKey']);
    Route::post('sites/{id}/disconnect', [AdminSiteController::class, 'disconnect']);
    Route::post('sites/{id}/archive', [AdminSiteController::class, 'archive']);
    Route::post('sites/{id}/restore', [AdminSiteController::class, 'restore']);

    Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
    Route::post('subscriptions', [AdminSubscriptionController::class, 'store']);
    Route::get('subscriptions/{id}', [AdminSubscriptionController::class, 'show']);
    Route::post('subscriptions/{id}/change-plan', [AdminSubscriptionController::class, 'changePlan']);
    Route::post('subscriptions/{id}/cancel', [AdminSubscriptionController::class, 'cancel']);
    Route::post('subscriptions/{id}/reactivate', [AdminSubscriptionController::class, 'reactivate']);
    Route::post('subscriptions/{id}/extend-trial', [AdminSubscriptionController::class, 'extendTrial']);
    Route::post('subscriptions/{id}/override', [AdminSubscriptionController::class, 'override']);
    Route::get('subscriptions/{id}/portal-link', [AdminSubscriptionController::class, 'portalLink']);
    Route::post('subscriptions/{id}/replay-webhook', [AdminSubscriptionController::class, 'replayWebhook']);
    Route::post('subscriptions/{id}/resync', [AdminSubscriptionController::class, 'resync']);
    Route::get('sync-logs', [AdminSyncLogController::class, 'index']);
    Route::get('reports', [AdminReportController::class, 'index']);
    Route::get('usage', [AdminUsageController::class, 'index']);
    Route::get('billing/ltd-campaign', [AdminBillingController::class, 'campaign']);
    Route::get('billing/organizations/{organizationId}/credits', [AdminBillingController::class, 'organizationCredits']);
    Route::post('billing/organizations/{organizationId}/credits/adjust', [AdminBillingController::class, 'adjustCredits'])->middleware('reauth');
    Route::get('audit-logs', [AdminAuditLogController::class, 'index']);

    // Impersonation / support mode (spec §13.9). `reason` is required by
    // the controller's validation — there's no way to start a session
    // without one.
    Route::get('impersonations', [AdminImpersonationController::class, 'index']);
    // Starting a support session reads another tenant's data as them —
    // a stolen super-admin token must not be enough on its own.
    Route::post('impersonate', [AdminImpersonationController::class, 'start'])->middleware('reauth');

    // Licenses (spec §13.6). Destructive actions (revoke, disconnect-all)
    // require an explicit `confirm: true` in the request body — checked by
    // Laravel's `accepted` validation rule, not implied by the HTTP verb.
    Route::get('licenses', [AdminLicenseController::class, 'index']);
    Route::post('licenses', [AdminLicenseController::class, 'store']);
    Route::get('licenses/{id}', [AdminLicenseController::class, 'show']);
    Route::post('licenses/{id}/regenerate', [AdminLicenseController::class, 'regenerate']);
    Route::post('licenses/{id}/suspend', [AdminLicenseController::class, 'suspend']);
    Route::post('licenses/{id}/reactivate', [AdminLicenseController::class, 'reactivate']);
    Route::post('licenses/{id}/revoke', [AdminLicenseController::class, 'revoke']);
    Route::post('licenses/{id}/disconnect-all', [AdminLicenseController::class, 'disconnectAll']);
    Route::patch('licenses/{id}/activation-limit', [AdminLicenseController::class, 'overrideActivationLimit']);
    Route::delete('activations/{id}', [AdminLicenseController::class, 'revokeActivation']);

    // AppSumo Licensing API v2 intake records (partner guide: keys must be
    // searchable by support).
    Route::get('appsumo/licenses', [AdminAppSumoLicenseController::class, 'index']);

    // External/plugin/vendor entitlements are kept separate from the
    // Insightistic license lifecycle, with encrypted key storage.
    Route::get('managed-licenses', [AdminManagedLicenseController::class, 'index']);
    Route::post('managed-licenses', [AdminManagedLicenseController::class, 'store']);
    Route::patch('managed-licenses/{id}', [AdminManagedLicenseController::class, 'update']);

    // Plan / feature registry editor (spec §13.7).
    Route::get('plans', [AdminPlanController::class, 'index']);
    Route::post('plans', [AdminPlanController::class, 'store']);
    Route::get('plans/{id}', [AdminPlanController::class, 'show']);
    Route::patch('plans/{id}', [AdminPlanController::class, 'update']);
    Route::get('plans/{id}/impact', [AdminPlanController::class, 'impact']);
});

// ---- Plugin license endpoints (spec §4) ------------------------------------
// activate is authenticated BY the license key itself (public + throttled);
// everything after activation is HMAC-signed with the connector credentials
// issued in the activate response.
Route::prefix('plugin')->group(function () {
    Route::post('activate', [PluginLicenseController::class, 'activate'])->middleware('throttle:license');

    Route::middleware(['connector.auth', 'throttle:license'])->group(function () {
        Route::post('validate', [PluginLicenseController::class, 'validate']);
        Route::get('features', [PluginLicenseController::class, 'features']);
        Route::post('deactivate', [PluginLicenseController::class, 'deactivate']);
    });

    // Insightistic Cloud AI (docs/APP-CONNECT-WORKFLOW.md §8). Its own,
    // tighter limiter — this proxies to a real AI provider call, unlike the
    // cheap license check-ins above.
    Route::post('ai-insights', [PluginAiController::class, 'generate'])
        ->middleware(['connector.auth', 'throttle:plugin-ai']);

    // The WordPress plugin renders the premium PDF locally after this
    // successful, credit-metered charge. Keeping it on the same HMAC route
    // prevents blank report tabs and avoids exposing a public charge action.
    Route::post('ai-reports', [PluginAiController::class, 'chargeReport'])
        ->middleware(['connector.auth', 'throttle:plugin-ai']);

    // Hosted Cloudflare Traffic Insights (docs/APP-CONNECT-WORKFLOW.md §10).
    // Read-heavy but plugin-side cached for 15 min; the connector limiter
    // (shared with WooCommerce sync traffic) is generous enough.
    Route::post('cloudflare-traffic', [PluginCloudflareController::class, 'trafficData'])
        ->middleware(['connector.auth', 'throttle:connector']);
});

// ---- Connector (WordPress plugin, authed by HMAC signature) ---------------
Route::prefix('connector/v1')->middleware(['connector.auth', 'throttle:connector'])->group(function () {
    Route::post('ping', [PingController::class, 'ping']);
    Route::post('handshake', [HandshakeController::class, 'handshake']);
    Route::post('site-health', [SyncController::class, 'siteHealth']);
    Route::post('orders/bulk', [SyncController::class, 'ordersBulk']);
    Route::post('products/bulk', [SyncController::class, 'productsBulk']);
    Route::post('customers/bulk', [SyncController::class, 'customersBulk']);
    Route::post('sync-complete', [SyncController::class, 'syncComplete']);

    // Connector v2 (spec §7.2/§8, Milestone 3) — traffic/SEO/performance/
    // engagement/broken-link ingestion, on top of v1's commerce sync above.
    Route::post('sync/start', [SyncV2Controller::class, 'start']);
    Route::post('sync/traffic/daily', [SyncV2Controller::class, 'trafficDaily']);
    Route::post('sync/traffic/dimensions', [SyncV2Controller::class, 'trafficDimensions']);
    Route::post('sync/seo/daily', [SyncV2Controller::class, 'seoDaily']);
    Route::post('sync/seo/queries', [SyncV2Controller::class, 'seoQueries']);
    Route::post('sync/seo/pages', [SyncV2Controller::class, 'seoPages']);
    Route::post('sync/performance/runs', [SyncV2Controller::class, 'performanceRuns']);
    Route::post('sync/engagement/daily', [SyncV2Controller::class, 'engagementDaily']);
    Route::post('sync/broken-links', [SyncV2Controller::class, 'brokenLinks']);

    // Cloudflare edge analytics pushed from the plugin's own Zone ID + token
    // (Settings → Cloudflare BYO path) — same tables the nightly pull fills.
    Route::post('sync/cloudflare/daily', [SyncV2Controller::class, 'cloudflareDaily']);

    // Email analytics from WordPress-side platforms (FluentCRM, AutomateWoo,
    // WooCommerce mail) — the SaaS can't reach these, so the plugin pushes.
    Route::post('sync/email/campaigns', [\App\Http\Controllers\Api\Connector\EmailSyncController::class, 'campaigns']);

    // Connector v2 — WordPress content → AI knowledge base (opt-in,
    // plugin-side privacy toggle; ingestion is queued + idempotent).
    Route::post('content/bulk', [\App\Http\Controllers\Api\Connector\ContentSyncController::class, 'contentBulk']);

    // IndexNow bridge (Phase 6, roadmap 11-BING-WEBMASTER-INDEXNOW) — the
    // SaaS generates/holds the key; the plugin hosts {key}.txt on the site,
    // confirms it (?confirmed=1), then pushes eligible URLs for submission.
    // GET and POST both land on key() so the plugin can confirm either way.
    Route::get('indexnow/key', [\App\Http\Controllers\Api\Connector\ConnectorIndexNowController::class, 'key']);
    Route::post('indexnow/key', [\App\Http\Controllers\Api\Connector\ConnectorIndexNowController::class, 'key']);
    Route::post('indexnow/submit', [\App\Http\Controllers\Api\Connector\ConnectorIndexNowController::class, 'submit']);
});

// ---- Stripe webhook (no tenant, signature-verified) -----------------------
// throttle:webhooks bounds signature-check flooding (the check itself fails
// closed; this just keeps the HMAC + log cost finite).
Route::post('billing/webhook/stripe', [BillingController::class, 'webhook'])->middleware('throttle:webhooks');

// ---- Paddle webhook (same shape: no tenant, HMAC-verified, queued) --------
// Deliberately outside every middleware group — Paddle retries with its own
// schedule and its servers carry no bearer token.
Route::post('billing/webhook/paddle', [PaddleWebhookController::class, '__invoke'])->middleware('throttle:webhooks');

// ---- AppSumo Licensing API v2 (partner guide: webhooks + OAuth) ------------
// Webhook: no auth, no tenant — the HMAC signature (X-Appsumo-Signature over
// timestamp + raw body under the licensing API key) is the authentication;
// the endpoint always acks 200 {"event": …, "success": true}.
Route::post('appsumo/webhook', [AppSumoWebhookController::class, '__invoke'])->middleware('throttle:webhooks');

// OAuth redirect URL: browser navigation from AppSumo after purchase. Same
// throttle as the other OAuth callbacks; it answers 200 OK (no code) for the
// Partner Portal validation ping.
Route::get('appsumo/oauth/callback', [AppSumoOAuthController::class, 'callback'])->middleware('throttle:auth');

// Buyer claim (final OAuth step): the OAuth controller upserts the license
// row and redirects the buyer to the SPA claim page, which calls these. No
// auth middleware — the license key + password are the only credentials;
// POST verifies a password so it throttles like the other auth endpoints,
// GET is the unauthenticated page pre-check.
Route::get('appsumo/claim/{licenseKey}', [AppSumoClaimController::class, 'show'])->middleware('throttle:public');
Route::post('appsumo/claim', [AppSumoClaimController::class, 'claim'])->middleware('throttle:auth');
Route::post('appsumo/claim/authenticated', [AppSumoClaimController::class, 'claimAuthenticated'])
    ->middleware(['auth:sanctum', 'restrict.impersonation', 'throttle:auth']);

// ---- Uptime monitoring (Phase 12, docs/roadmap-2026/16-PERFORMANCE-UPTIME.md)
// Appended as its own group rather than spliced into the site.access group
// above (this file is co-edited on the branch); the middleware stack is
// identical, so {site} resolution, tenancy 404s and impersonation
// restrictions behave exactly as they do for every other site surface.
// Reads are open to anyone with site access; configuring/deleting the
// monitor and the immediate check-now are owner/admin — like every other
// queued heavy action (each probe costs an outbound request + history rows).
Route::middleware(['auth:sanctum', 'tenant', 'restrict.impersonation', 'site.access'])->group(function () {
    Route::get('sites/{site}/uptime', [\App\Http\Controllers\Api\UptimeController::class, 'index']);
    Route::get('sites/{site}/uptime/incidents', [\App\Http\Controllers\Api\UptimeController::class, 'incidents']);

    Route::middleware('role:owner,admin')->group(function () {
        Route::post('sites/{site}/uptime/monitors', [\App\Http\Controllers\Api\UptimeController::class, 'store']);
        Route::delete('sites/{site}/uptime/monitors/{monitor}', [\App\Http\Controllers\Api\UptimeController::class, 'destroy']);
        Route::post('sites/{site}/uptime/check', [\App\Http\Controllers\Api\UptimeController::class, 'check']);
    });
});
