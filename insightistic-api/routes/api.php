<?php

use App\Http\Controllers\Api\Admin\AuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\ImpersonationController as AdminImpersonationController;
use App\Http\Controllers\Api\Admin\LicenseController as AdminLicenseController;
use App\Http\Controllers\Api\Admin\OrganizationController as AdminOrganizationController;
use App\Http\Controllers\Api\Admin\OverviewController as AdminOverviewController;
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
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\IntegrationsController;
use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\PerformanceController;
use App\Http\Controllers\Api\PluginAiController;
use App\Http\Controllers\Api\PluginCloudflareController;
use App\Http\Controllers\Api\PluginLicenseController;
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

// ---- Public auth (throttle:auth = brute-force protection) -----------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:auth');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:auth');

    // Social login (Google / Microsoft / GitHub). Buttons appear in the SPA
    // only for providers returned by the `oauth/providers` endpoint.
    Route::get('oauth/providers', [OAuthController::class, 'providers']);
    Route::get('oauth/{provider}/redirect', [OAuthController::class, 'redirect']);
    Route::get('oauth/{provider}/callback', [OAuthController::class, 'callback']);
});

// ---- Authenticated identity (no organization required) --------------------
// `me`/`logout` only need to know who the token belongs to, not which org is
// active — gating them behind `tenant` would 403 any authenticated user with
// zero organizations (e.g. a manually-created super admin, or a team member
// just removed from their only org), logging them out on every page refresh.
Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

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
    Route::post('billing/checkout', [BillingController::class, 'checkout'])->middleware('role:owner');
    Route::post('billing/portal', [BillingController::class, 'portal'])->middleware('role:owner');
    Route::post('billing/cancel', [BillingController::class, 'cancel'])->middleware('role:owner');
    Route::post('billing/reactivate', [BillingController::class, 'reactivate'])->middleware('role:owner');

    // Licenses tab (spec §2). Raw keys only ever appear in regenerate/register responses.
    Route::get('licenses', [LicenseController::class, 'index']);
    Route::post('licenses/{license}/regenerate', [LicenseController::class, 'regenerate'])->middleware('role:owner');
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

        Route::get('sites/{site}/integrations', [IntegrationsController::class, 'index']);

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
        Route::get('sites/{site}/exports/traffic.csv', [ExportController::class, 'traffic']);
        Route::get('sites/{site}/exports/seo.csv', [ExportController::class, 'seo']);
        Route::get('sites/{site}/exports/performance.csv', [ExportController::class, 'performance']);
        Route::get('sites/{site}/exports/commerce.csv', [ExportController::class, 'commerce']);
    });

    // Report view + send (not site-scoped in the URL; access checked in controller).
    Route::get('reports/{report}', [ReportController::class, 'show']);
    Route::post('reports/{report}/send-email', [ReportController::class, 'sendEmail']);
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
    Route::post('users/{id}/promote', [AdminUserController::class, 'promote']);
    Route::post('users/{id}/demote', [AdminUserController::class, 'demote']);
    Route::post('users/{id}/archive', [AdminUserController::class, 'archive']);
    Route::post('users/{id}/restore', [AdminUserController::class, 'restore']);

    Route::get('sites', [AdminSiteController::class, 'index']);
    Route::get('sites/{id}', [AdminSiteController::class, 'show']);
    Route::post('sites/{id}/disconnect', [AdminSiteController::class, 'disconnect']);
    Route::post('sites/{id}/archive', [AdminSiteController::class, 'archive']);
    Route::post('sites/{id}/restore', [AdminSiteController::class, 'restore']);

    Route::get('subscriptions', [AdminSubscriptionController::class, 'index']);
    Route::get('sync-logs', [AdminSyncLogController::class, 'index']);
    Route::get('reports', [AdminReportController::class, 'index']);
    Route::get('usage', [AdminUsageController::class, 'index']);
    Route::get('audit-logs', [AdminAuditLogController::class, 'index']);

    // Impersonation / support mode (spec §13.9). `reason` is required by
    // the controller's validation — there's no way to start a session
    // without one.
    Route::get('impersonations', [AdminImpersonationController::class, 'index']);
    Route::post('impersonate', [AdminImpersonationController::class, 'start']);

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
});

// ---- Stripe webhook (no tenant, signature-verified) -----------------------
Route::post('billing/webhook/stripe', [BillingController::class, 'webhook']);
