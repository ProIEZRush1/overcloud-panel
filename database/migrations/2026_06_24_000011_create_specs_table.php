<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->uuid('uuid')->unique();                     // public link for the client
            $table->unsignedInteger('version')->default(1);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('content');                            // pages[], features[], deliverables[], timeline, notes
            $table->string('status')->default('draft')->index();
            $table->string('pdf_path')->nullable();
            $table->text('client_feedback')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('agreed_at')->nullable();
            $table->timestamp('changes_requested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specs');
    }
};
