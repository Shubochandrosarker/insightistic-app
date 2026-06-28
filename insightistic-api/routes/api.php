<?php

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\BrandSettingsController;
use App\Http\Controllers\Api\Connector\HandshakeController;
use App\Http\Controllers\Api\Connector\PingController;
use App\Http\Controllers\Api\Connector\SyncController;
use App\Http\Controllers\Api\InsightController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SiteController;
use App\Http\Controllers\Api\TeamController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Insightistic API
|--------------------------------------------------------------------------
| Implemented now: auth, organization, sites, connector handshake.
| Future endpoints are listed as commented stubs so the full surface is
| visible — uncomment as each week's controller lands.
*/

// ---- Public auth ----------------------------------------------------------
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password', [AuthController::class, 'resetPassword']);

    // Social login (Google / Microsoft / GitHub). Buttons appear in the SPA
    // only for providers returned by the `oauth/providers` endpoint.
    Route::get('oauth/providers', [OAuthController::class, 'providers']);
    Route::get('oauth/{provider}/redirect', [OAuthController::class, 'redirect']);
    Route::get('oauth/{provider}/callback', [OAuthController::class, 'callback']);
});

// ---- Authenticated app (user JWT/Sanctum + tenant resolution) -------------
Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);

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

        // AI insights (Week 4)
        Route::get('sites/{site}/insights', [InsightController::class, 'index']);
        Route::post('sites/{site}/insights/generate', [InsightController::class, 'generate']);
        Route::get('sites/{site}/insights/{insight}', [InsightController::class, 'show']);
        Route::post('sites/{site}/insights/{insight}/mark-read', [InsightController::class, 'markRead']);

        // Reports (Week 4)
        Route::get('sites/{site}/reports', [ReportController::class, 'index']);
        Route::post('sites/{site}/reports/generate', [ReportController::class, 'generate']);
    });

    // Report view + send (not site-scoped in the URL; access checked in controller).
    Route::get('reports/{report}', [ReportController::class, 'show']);
    Route::post('reports/{report}/send-email', [ReportController::class, 'sendEmail']);
});

// ---- Connector (WordPress plugin, authed by HMAC signature) ---------------
Route::prefix('connector/v1')->middleware('connector.auth')->group(function () {
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
