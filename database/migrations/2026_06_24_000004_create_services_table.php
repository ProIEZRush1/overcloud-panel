<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();             // landing / website / webapp / ecommerce / app
            $table->unsignedBigInteger('base_price_cents')->default(0);
            $table->char('currency', 3)->default('MXN');
            $table->unsignedInteger('included_pages')->default(1);
            $table->unsignedBigInteger('per_page_price_cents')->default(0);
            $table->unsignedBigInteger('per_language_price_cents')->default(0);
            $table->unsignedInteger('default_timeline_days')->nullable();
            $table->foreignId('default_maintenance_plan_id')->nullable()
                ->constrained('maintenance_plans')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
