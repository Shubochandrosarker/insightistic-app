<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();              // starter|growth|business|agency|agency_pro
            $table->integer('price_monthly')->default(0);  // cents
            $table->integer('price_yearly')->default(0);   // cents
            $table->string('stripe_price_id_monthly')->nullable();
            $table->string('stripe_price_id_yearly')->nullable();
            $table->integer('site_limit')->default(1);
            $table->integer('user_limit')->default(1);
            $table->integer('ai_insight_limit')->default(20);
            $table->integer('report_limit')->default(4);
            $table->boolean('white_label_enabled')->default(false);
            $table->boolean('custom_domain_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
    }
};
