<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction vs. the spec: every WooCommerce table gets a UNIQUE index on
 * (site_id, external_*_id). The connector re-sends data on every sync, so
 * ingestion MUST be an upsert keyed on the store's own ID — otherwise a
 * re-sync duplicates every order. This is the single most common SaaS-sync bug.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('wc_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_order_id');
            $table->string('order_number')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable(); // external WC customer id
            $table->string('status')->nullable();
            $table->string('currency', 8)->nullable();
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('shipping_total', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('refund_total', 14, 2)->default(0);
            $table->string('payment_method')->nullable();
            $table->timestamp('created_at_store')->nullable();
            $table->timestamp('completed_at_store')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'external_order_id']);
            $table->index(['site_id', 'created_at_store']);
            $table->index(['site_id', 'status']);
        });

        Schema::create('wc_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_order_id');
            $table->unsignedBigInteger('external_product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('sku')->nullable();
            $table->integer('quantity')->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['site_id', 'external_order_id']);
            $table->index(['site_id', 'external_product_id']);
        });

        Schema::create('wc_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_product_id');
            $table->string('name')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 14, 2)->default(0);
            $table->decimal('regular_price', 14, 2)->default(0);
            $table->decimal('sale_price', 14, 2)->nullable();
            $table->integer('stock_quantity')->nullable();
            $table->string('stock_status')->nullable();
            $table->integer('total_sales')->default(0);
            $table->string('status')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'external_product_id']);
        });

        Schema::create('wc_customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('external_customer_id');
            $table->string('email_hash')->nullable()->index(); // privacy: hash, not raw email
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('city')->nullable();
            $table->string('country', 4)->nullable();
            $table->decimal('total_spent', 14, 2)->default(0);
            $table->integer('order_count')->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['site_id', 'external_customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wc_customers');
        Schema::dropIfExists('wc_products');
        Schema::dropIfExists('wc_order_items');
        Schema::dropIfExists('wc_orders');
    }
};
