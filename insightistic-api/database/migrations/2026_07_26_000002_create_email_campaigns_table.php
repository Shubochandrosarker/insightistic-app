<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email/campaign analytics (spec Phase 3.4).
 *
 * One row per campaign or automation per provider, holding the funnel the
 * spec asks for: sent → delivered → opened → clicked, plus the negative
 * signals (bounced, unsubscribed, complained) and commerce attribution
 * (conversions, revenue).
 *
 * Deliberately provider-agnostic: Mailchimp and Klaviyo are pulled by the
 * SaaS over their APIs, while FluentCRM, AutomateWoo and WooCommerce's own
 * mail are pushed by the WordPress connector. Both paths upsert on
 * (site_id, provider, external_id) so a re-sync updates rather than
 * duplicates, and a site can run several providers at once.
 *
 * Counters are nullable rather than defaulted to 0 because "this provider
 * does not report opens" and "nobody opened it" are different facts, and the
 * dashboard has to be able to tell them apart instead of showing a
 * confident 0%.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();

            // mailchimp|klaviyo|fluentcrm|automatewoo|woocommerce
            $table->string('provider', 30);
            // Provider's own id for this campaign/automation.
            $table->string('external_id', 191);

            $table->string('name', 300);
            // campaign|automation|transactional
            $table->string('type', 20)->default('campaign');
            // draft|scheduled|sending|sent|paused
            $table->string('status', 20)->default('sent');
            $table->string('subject', 500)->nullable();

            $table->timestamp('sent_at')->nullable();

            $table->unsignedInteger('recipients')->nullable();
            $table->unsignedInteger('sent')->nullable();
            $table->unsignedInteger('delivered')->nullable();
            $table->unsignedInteger('bounced')->nullable();
            $table->unsignedInteger('opened')->nullable();
            $table->unsignedInteger('unique_opens')->nullable();
            $table->unsignedInteger('clicked')->nullable();
            $table->unsignedInteger('unique_clicks')->nullable();
            $table->unsignedInteger('unsubscribed')->nullable();
            $table->unsignedInteger('complained')->nullable();

            $table->unsignedInteger('conversions')->nullable();
            $table->decimal('revenue', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();

            $table->jsonb('metadata_json')->nullable();

            $table->timestamps();

            $table->unique(['site_id', 'provider', 'external_id'], 'email_campaigns_identity_uniq');
            // The dashboard's default read: this site's campaigns, newest first.
            $table->index(['site_id', 'sent_at'], 'email_campaigns_site_sent_idx');
            $table->index(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaigns');
    }
};
