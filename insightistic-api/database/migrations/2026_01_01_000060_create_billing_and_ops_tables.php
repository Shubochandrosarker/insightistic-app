<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id')->nullable()->index();
            $table->string('stripe_subscription_id')->nullable()->index();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status')->default('incomplete'); // active|trialing|past_due|canceled|incomplete
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamps();
        });

        Schema::create('usage_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->integer('ai_insights_used')->default(0);
            $table->integer('reports_generated')->default(0);
            $table->integer('sites_connected')->default(0);
            $table->timestamps();

            $table->unique(['organization_id', 'period']);
        });

        Schema::create('brand_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('logo_url')->nullable();
            $table->string('primary_color', 9)->default('#2563EB');
            $table->string('accent_color', 9)->default('#10B981');
            $table->string('custom_domain')->nullable();
            $table->string('email_from_name')->nullable();
            $table->string('email_from_address')->nullable();
            $table->text('report_footer_text')->nullable();
            $table->timestamps();

            $table->unique('organization_id');
        });

        // Mistake #4 in the spec: build sync logs from day one.
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('job');        // orders|products|customers|site_health|email_events|handshake
            $table->string('status');     // started|success|partial|failed
            $table->integer('records')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'job']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
        Schema::dropIfExists('brand_settings');
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('subscriptions');
    }
};
