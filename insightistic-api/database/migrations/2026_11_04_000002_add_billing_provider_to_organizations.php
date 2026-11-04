<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dual-provider billing (Paddle + Stripe). Adds the org-level provider and
 * Stripe customer id, and backfills both orgs and subscriptions:
 *   - paddle: org has paddle_customer_id OR its subscription is paddle-linked
 *   - stripe: subscription has a stripe_subscription_id and no paddle link
 * Reversible: down() drops the org columns and nulls only the subscription
 * rows this migration backfilled (billing_provider_backfilled marker), since
 * subscriptions.billing_provider pre-existed and may hold real values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('billing_provider', 16)->nullable()->after('paddle_customer_id');
            $table->string('stripe_customer_id', 190)->nullable()->unique()->after('billing_provider');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->boolean('billing_provider_backfilled')->default(false);
        });

        // Subscriptions first (provider_subscription_id is Paddle's sub_… id).
        DB::table('subscriptions')->whereNull('billing_provider')
            ->where(fn ($q) => $q->where('provider_subscription_id', 'like', 'sub_%')->orWhere('provider_customer_id', 'like', 'ctm_%'))
            ->update(['billing_provider' => 'paddle', 'billing_provider_backfilled' => true]);
        DB::table('subscriptions')->whereNull('billing_provider')->whereNotNull('stripe_subscription_id')
            ->update(['billing_provider' => 'stripe', 'billing_provider_backfilled' => true]);

        DB::table('organizations')->whereNotNull('paddle_customer_id')->update(['billing_provider' => 'paddle']);
        DB::table('organizations')->whereNull('billing_provider')
            ->whereIn('id', DB::table('subscriptions')->where('billing_provider', 'paddle')->select('organization_id'))
            ->update(['billing_provider' => 'paddle']);
        DB::table('organizations')->whereNull('billing_provider')
            ->whereIn('id', DB::table('subscriptions')->where('billing_provider', 'stripe')->select('organization_id'))
            ->update(['billing_provider' => 'stripe']);

        // Copy the legacy Stripe customer id up to the org (first wins on dupes).
        foreach (DB::table('subscriptions')->whereNotNull('stripe_customer_id')->orderBy('id')->get(['organization_id', 'stripe_customer_id']) as $row) {
            if (! DB::table('organizations')->where('stripe_customer_id', $row->stripe_customer_id)->exists()) {
                DB::table('organizations')->where('id', $row->organization_id)->whereNull('stripe_customer_id')
                    ->update(['stripe_customer_id' => $row->stripe_customer_id]);
            }
        }
    }

    public function down(): void
    {
        DB::table('subscriptions')->where('billing_provider_backfilled', true)->update(['billing_provider' => null]);
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('billing_provider_backfilled'));
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['stripe_customer_id']);
            $table->dropColumn(['billing_provider', 'stripe_customer_id']);
        });
    }
};
