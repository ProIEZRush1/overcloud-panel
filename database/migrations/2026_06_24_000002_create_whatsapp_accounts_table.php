<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('session_name')->unique();          // id used by the Baileys gateway
            $table->string('phone')->nullable();                // resolved once paired
            $table->string('jid')->nullable();                  // own JID once connected
            $table->string('status')->default('disconnected')->index();
            $table->boolean('is_default')->default(false);
            $table->boolean('auto_reply')->default(true);       // let the AI agent answer on this number
            $table->timestamp('last_connected_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_accounts');
    }
};
