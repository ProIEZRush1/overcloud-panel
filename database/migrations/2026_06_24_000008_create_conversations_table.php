<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->string('contact_jid');
            $table->string('contact_phone')->nullable();
            $table->string('contact_name')->nullable();
            $table->boolean('is_group')->default(false);
            $table->string('status')->default('bot');           // bot / human / snoozed / closed
            $table->boolean('ai_enabled')->default(true);
            $table->unsignedInteger('unread_count')->default(0);
            $table->string('last_message_preview')->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('snoozed_until')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'contact_jid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
