<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->decimal('revenue', 14, 2)->default(0);
            $table->integer('orders')->default(0);
            $table->integer('refunds')->default(0);
            $table->decimal('average_order_value', 14, 2)->default(0);
            $table->integer('new_customers')->default(0);
            $table->integer('returning_customers')->default(0);
            $table->integer('products_sold')->default(0);
            $table->integer('failed_orders')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'date']); // one snapshot per site per day
        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // revenue_drop|no_orders|refund_spike|low_stock|failed_payment_spike|sync_failed
            $table->string('title');
            $table->text('message')->nullable();
            $table->string('severity')->default('medium'); // low|medium|high
            $table->boolean('is_resolved')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'is_resolved']);
        });

        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // daily|weekly|monthly|risk|opportunity|alert|product|customer
            $table->string('title');
            $table->text('summary')->nullable();
            $table->text('recommendation')->nullable();
            $table->string('severity')->default('medium');
            $table->integer('priority_score')->nullable();
            $table->jsonb('source_data_json')->nullable();
            $table->string('ai_model')->nullable();
            $table->string('status')->default('unread'); // unread|read|done
            $table->timestamps();

            $table->index(['site_id', 'type']);
        });

        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('report_type'); // weekly|monthly|custom
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('html_url')->nullable();
            $table->string('pdf_url')->nullable();
            $table->string('sent_to')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('frequency'); // weekly|monthly
            $table->text('recipients')->nullable();
            $table->string('send_day')->nullable();
            $table->string('send_time')->nullable();
            $table->string('timezone')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('client_reports');
        Schema::dropIfExists('ai_insights');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('metric_snapshots');
    }
};
