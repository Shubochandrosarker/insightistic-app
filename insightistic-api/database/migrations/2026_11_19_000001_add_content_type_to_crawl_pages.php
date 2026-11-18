<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('crawl_pages', 'content_type')) {
            Schema::table('crawl_pages', function (Blueprint $table) {
                $table->string('content_type', 160)
                    ->nullable()
                    ->after('status_code')
                    ->comment('HTTP Content-Type; HTML-only checks apply only to text/html and XHTML');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crawl_pages', 'content_type')) {
            Schema::table('crawl_pages', function (Blueprint $table) {
                $table->dropColumn('content_type');
            });
        }
    }
};
