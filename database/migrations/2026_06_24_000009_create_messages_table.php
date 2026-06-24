<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sent_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('wa_message_id')->nullable()->index();
            $table->string('direction');                        // in / out
            $table->string('type')->default('text');
            $table->string('sender_jid')->nullable();
            $table->longText('body')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->string('media_filename')->nullable();
            $table->text('caption')->nullable();
            $table->string('quoted_wa_message_id')->nullable();
            $table->string('status')->default('pending');
            $table->boolean('is_from_me')->default(false);
            $table->boolean('ai_generated')->default(false);
            $table->json('payload')->nullable();                // raw gateway payload
            $table->timestamp('wa_timestamp')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
