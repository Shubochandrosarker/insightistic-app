<?php

use App\Http\Controllers\Api\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Api\Admin\LicenseController as AdminLicenseController;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\OverviewController as AdminOverviewController;
use App\Http\Controllers\Api\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Api\Admin\SubscriptionController as AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\SyncLogController as AdminSyncLogController;
use App\Http\Controllers\Api\Admin\UsageController as AdminUsageController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AnalyticsController;
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
use App\Http\Controllers\Api\IntegrationsController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\MfaController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\PluginAiController;
use App\Http\Controllers\Api\PluginCloudflareController;
use App\Http\Controllers\Api\PluginLicenseController;
use App\Http\Controllers\Api\PublicPlanController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ReportScheduleController;
use App\Http\Controllers\Api\SeoController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\TeamController;
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

    // Team management (Week 5) — owner/admin can view & invite; owner-only mutations.
    Route::get('organizations/users', [TeamController::class, 'index'])->middleware('role:owner,admin');
    Route::post('organizations/users/invite', [TeamController::class, 'invite'])->middleware('role:owner,admin');
    Route::patch('organizations/users/{user}/role', [TeamController::class, 'updateRole'])->middleware('role:owner');
    Route::delete('organizations/users/{user}', [TeamController::class, 'remove'])->middleware('role:owner');

    // Billing (Week 5) — owner manages payment; owner/admin can view status.
    Route::get('billing/subscription', [BillingController::class, 'subscription'])->middleware('role:owner,admin');
    Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware(['role:owner', 'reauth']);
    Route::post('billing/portal', [BillingController::class, 'portal'])->middleware('role:owner');
    Route::post('billing/cancel', [BillingController::class, 'cancel'])->middleware(['role:owner', 'reauth']);
    Route::post('billing/reactivate', [BillingController::class, 'reactivate'])->middleware('role:owner');

    // Licenses tab (spec §2). Raw keys only ever appear in regenerate/register responses.
    Route::get('licenses', [LicenseController::class, 'index']);
    Route::post('licenses/{license}/regenerate', [LicenseController::class, 'regenerate'])->middleware(['role:owner', 'reauth']);
    Route::get('licenses/{license}/activations', [LicenseController::class, 'activations']);
    Route::get('licenses/{license}/events', [LicenseController::class, 'events']);
    Route::delete('activations/{activation}', [LicenseController::class, 'deactivateActivation'])->middleware('role:owner,admin');

    // Notifications / conversion prompts.
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/{notification}', [NotificationController::class, 'update']);

    // White-label (Week 5) — read for owner/admin; writes owner-only + plan-gated.
    Route::get('brand-settings', [BrandSettingsController::class, 'show'])->middleware('role:owner,admin');
    Route::patch('brand-settings', [BrandSettingsController::class, 'update'])->middleware('role:owner');
    Route::post('brand-settings/logo', [BrandSettingsController::class, 'uploadLogo'])->middleware('role:owner');

    Route::get('sites', [SiteController::class, 'index']);
    Route::post('sites', [SiteController::class, 'store']);

    Route::middleware('site.access')->group(function () {
        Route::get('sites/{site}', [SiteController::class, 'show']);
        Route::get('sites/{site}/health', [SiteController::class, 'health']);
        Route::get('sites/{site}/orders', [AnalyticsController::class, 'ordersList']);
        Route::post('sites/{site}/regenerate-api-key', [SiteController::class, 'regenerateApiKey']);

        // Analytics (Week 3) — read from precomputed snapshots + live tables.
        Route::get('sites/{site}/analytics/overview', [AnalyticsController::class, 'overview']);
        Route::get('sites/{site}/analytics/revenue', [AnalyticsController::class, 'revenue']);
        Route::get('sites/{site}/analytics/orders', [AnalyticsController::class, 'orders']);
        Route::get('sites/{site}/analytics/products', [AnalyticsController::class, 'products']);
        Route::get('sites/{site}/analytics/customers', [AnalyticsController::class, 'customers']);
        Route::get('sites/{site}/analytics/refunds', [AnalyticsController::class, 'refunds']);
        Route::get('sites/{site}/analytics/compare', [AnalyticsController::class, 'compare']);

        // Traffic / SEO / Performance (Milestone 4). Read the tables Milestone
        // 3 shipped; return honest empty states until connector v2 ingests.
        Route::get('sites/{site}/analytics/traffic/overview', [TrafficController::class, 'overview']);
        Route::get('sites/{site}/analytics/traffic/dimensions/{type}', [TrafficController::class, 'dimensions']);

        Route::get('sites/{site}/analytics/seo/overview', [SeoController::class, 'overview']);
        Route::get('sites/{site}/analytics/seo/queries', [SeoController::class, 'queries']);
        Route::get('sites/{site}/analytics/seo/pages', [SeoController::class, 'pages']);

        Route::get('sites/{site}/analytics/performance/history', [PerformanceController::class, 'history']);
        Route::get('sites/{site}/analytics/performance/latest', [PerformanceController::class, 'latest']);

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
            Route::post('sites/{site}/integrations/{source}/authorize-url', [IntegrationsController::class, 'authorizeUrl']);
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
        Route::post('sites/{site}/alerts/{alert}/resolve', [AlertController::class, 'resolve']);
        Route::post('sites/{site}/alerts/{alert}/reopen', [AlertController::class, 'reopen']);

        // AI insights (Week 4)
        Route::get('sites/{site}/insights', [InsightController::class, 'index']);
        Route::post('sites/{site}/insights/generate', [InsightController::class, 'generate']);
        Route::get('sites/{site}/insights/{insight}', [InsightController::class, 'show']);
        Route::post('sites/{site}/insights/{insight}/mark-read', [InsightController::class, 'markRead']);

        // Reports (Week 4)
        Route::get('sites/{site}/reports', [ReportController::class, 'index']);
        Route::post('sites/{site}/reports/generate', [ReportController::class, 'generate']);

        // Scheduled report delivery (Milestone 5, spec §12).
        Route::get('sites/{site}/report-schedules', [ReportScheduleController::class, 'index']);
        Route::post('sites/{site}/report-schedules', [ReportScheduleController::class, 'store']);
        Route::patch('sites/{site}/report-schedules/{schedule}', [ReportScheduleController::class, 'update']);
        Route::delete('sites/{site}/report-schedules/{schedule}', [ReportScheduleController::class, 'destroy']);

        // Bounded CSV exports (Milestone 5, spec §12).
        Route::middleware('throttle:exports')->group(function () {
            Route::get('sites/{site}/exports/traffic.csv', [ExportController::class, 'traffic']);
            Route::get('sites/{site}/exports/seo.csv', [ExportController::class, 'seo']);
            Route::get('sites/{site}/exports/performance.csv', [ExportController::class, 'performance']);
            Route::get('sites/{site}/exports/commerce.csv', [ExportController::class, 'commerce']);
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
    ->name('reports.download.pdf')
    ->middleware('signed');
Route::get('reports/{report}/html', [ReportController::class, 'downloadHtml'])
    ->name('reports.download.html')
    ->middleware('signed');

// ---- Super Admin (platform owner; auth + super_admin, NO tenant scope) -----
Route::prefix('admin')->middleware(['auth:sanctum', 'super_admin'])->group(function () {
    Route::get('overview', [AdminOverviewController::class, 'overview']);
    Route::get('system-health', [AdminOverviewController::class, 'systemHealth']);

    Route::get('organizations', [AdminOrganizationController::class, 'index']);
    Route::get('organizations/{id}', [AdminOrganizationController::class, 'show']);
    Route::patch('organizations/{id}', [AdminOrganizationController::class, 'update']);
    Route::post('organizations/{id}/archive', [AdminOrganizationController::class, 'archive']);
    Route::post('organizations/{id}/restore', [AdminOrganizationController::class, 'restore']);

    Route::get('users', [AdminUserController::class, 'index']);
    Route::get('users/{id}', [AdminUserController::class, 'show']);
    Route::patch('users/{id}', [AdminUserController::class, 'update']);
    // Super-admin promotion/demotion is the highest-privilege change in
    // the platform — always behind a fresh password confirmation.
    Route::post('users/{id}/promote', [AdminUserController::class, 'promote'])->middleware('reauth');
    Route::post('users/{id}/demote', [AdminUserController::class, 'demote'])->middleware('reauth');
    Route::post('users/{id}/archive', [AdminUserController::class, 'archive']);
    Route::post('users/{id}/restore', [AdminUserController::class, 'restore']);

    Route::get('sites', [AdminSiteController::class, 'index']);
    Route::get('sites/{id}', [AdminSiteController::class, 'show']);
    Route::post('sites/{id}/disconnect', [AdminSiteController::class, 'disconnect']);
    Route::post('sites/{id}/archive', [AdminSiteController::class, 'archive']);
    Route::post('sites/{id}/restore', [AdminSiteController::class, 'restore']);

    Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
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

    // Email analytics from WordPress-side platforms (FluentCRM, AutomateWoo,
    // WooCommerce mail) — the SaaS can't reach these, so the plugin pushes.
    Route::post('sync/email/campaigns', [\App\Http\Controllers\Api\Connector\EmailSyncController::class, 'campaigns']);

    // Connector v2 — WordPress content → AI knowledge base (opt-in,
    // plugin-side privacy toggle; ingestion is queued + idempotent).
    Route::post('content/bulk', [\App\Http\Controllers\Api\Connector\ContentSyncController::class, 'contentBulk']);
});

// ---- Stripe webhook (no tenant, signature-verified) -----------------------
Route::post('billing/webhook/stripe', [BillingController::class, 'webhook']);
