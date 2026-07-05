<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * License layer (spec §4/§5/§7): org-level license keys in front of the
 * per-site HMAC connector credentials, plus the plugin event / email /
 * notification logs that the automation phases build on.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Plan feature vocabulary + "Best Value" flag for the pricing page.
        Schema::table('plans', function (Blueprint $table) {
            $table->jsonb('features_json')->nullable()->after('custom_domain_enabled');
            $table->boolean('is_featured')->default(false)->after('is_active');
        });

        Schema::create('licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            // Raw keys are never stored — HMAC-SHA256(app key, raw) + last 4.
            $table->char('license_key_hash', 64)->unique();
            $table->char('license_key_last4', 4);
            $table->string('status', 20)->default('trialing');
            // active | trialing | expired | cancelled | suspended | payment_failed | revoked
            $table->unsignedInteger('activation_limit')->default(1);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_validated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('license_activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('site_url');
            $table->string('normalized_site_url');
            $table->char('site_hash', 64); // sha256(normalized_site_url)
            $table->string('wp_version', 20)->nullable();
            $table->string('php_version', 20)->nullable();
            $table->string('plugin_version', 20)->nullable();
            $table->boolean('woocommerce_active')->default(false);
            $table->string('environment', 20)->default('production'); // production|staging|local
            $table->string('status', 20)->default('active');          // active|deactivated|blocked
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();
            $table->unique(['license_id', 'site_hash']);
            $table->index(['license_id', 'status']);
        });

        Schema::create('plugin_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('license_activation_id')->nullable()->constrained('license_activations')->nullOnDelete();
            $table->string('event_type', 40);
            // activated | reactivated | deactivated | validation_ok | validation_denied
            // | limit_reached | key_regenerated | revoked | feature_locked_click
            $table->jsonb('payload_json')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['license_id', 'event_type']);
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email_type', 60);
            // Dedupe key: one send per (org, type, period_key). period_key lets
            // recurring types (weekly reports) re-fire per period.
            $table->string('period_key', 40)->default('once');
            $table->string('recipient');
            $table->string('subject');
            $table->string('status', 20)->default('queued'); // queued|sent|failed|skipped
            $table->string('provider_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['organization_id', 'email_type', 'period_key']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 60);
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('action_url')->nullable();
            $table->string('status', 20)->default('unread'); // unread|read|dismissed
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('read_at')->nullable();
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('plugin_events');
        Schema::dropIfExists('license_activations');
        Schema::dropIfExists('licenses');
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['features_json', 'is_featured']);
        });
    }
};
