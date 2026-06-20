<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('domain')->nullable();
            $table->string('platform')->default('woocommerce'); // wordpress|woocommerce
            // HMAC connector credentials. key_id is the public identifier sent
            // on every request; secret is stored AES-encrypted (Laravel cast) and
            // never leaves the server — the plugin holds its own copy to sign with.
            $table->string('connector_key_id')->nullable()->unique();
            $table->text('connector_secret')->nullable(); // encrypted at rest
            $table->string('connection_status')->default('pending'); // pending|connected|error|disconnected
            $table->timestamp('last_sync_at')->nullable();
            $table->string('timezone')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('wp_version')->nullable();
            $table->string('wc_version')->nullable();
            $table->string('plugin_version')->nullable();
            $table->timestamps();

            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
