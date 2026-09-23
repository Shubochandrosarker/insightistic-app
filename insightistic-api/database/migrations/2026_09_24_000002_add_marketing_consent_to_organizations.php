<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('marketing_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['marketing_consent', 'marketing_consent_at']);
        });
    }
};
