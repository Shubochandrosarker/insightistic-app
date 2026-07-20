<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Explicit display order for the plan/feature registry editor (spec §13.7). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->integer('sort_order')->default(0)->after('is_featured');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
