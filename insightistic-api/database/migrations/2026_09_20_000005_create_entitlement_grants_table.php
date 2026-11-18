<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Access grants outside a recurring subscription: AppSumo lifetime purchases,
 * manual comps and promotions. The unique
 * (source, source_reference) pair prevents the same completed transaction
 * from granting twice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('entitlement_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 32);                  // appsumo_lifetime|manual|promotion
            $table->string('source_reference');
            $table->json('features_json')->nullable();
            $table->json('limits_json')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'source_reference']);
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_grants');
    }
};
