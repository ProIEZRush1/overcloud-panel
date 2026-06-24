<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('spec_id')->nullable()->constrained('specs')->nullOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()
                ->constrained('maintenance_plans')->nullOnDelete();
            $table->uuid('uuid')->unique();                     // public link for the client
            $table->string('number')->unique();                 // folio e.g. OVC-2026-0001
            $table->unsignedInteger('version')->default(1);
            $table->char('currency', 3)->default('MXN');
            $table->unsignedBigInteger('subtotal_cents')->default(0);
            $table->unsignedBigInteger('discount_cents')->default(0);
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->unsignedBigInteger('total_cents')->default(0);
            $table->unsignedTinyInteger('deposit_percent')->default(50);
            $table->unsignedBigInteger('deposit_cents')->default(0);
            $table->unsignedBigInteger('maintenance_monthly_cents')->default(0);
            $table->string('status')->default('draft')->index();
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
