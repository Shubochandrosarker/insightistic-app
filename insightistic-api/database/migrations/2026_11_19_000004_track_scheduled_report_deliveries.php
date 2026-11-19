<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_reports', function (Blueprint $table): void {
            $table->foreignId('report_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['report_schedule_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('client_reports', function (Blueprint $table): void {
            $table->dropIndex(['report_schedule_id', 'sent_at']);
            $table->dropConstrainedForeignId('report_schedule_id');
        });
    }
};
