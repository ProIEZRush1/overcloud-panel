<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()
                ->constrained('maintenance_plans')->nullOnDelete();
            $table->string('status')->default('active')->index();
            $table->unsignedBigInteger('monthly_price_cents')->default(0);
            $table->char('currency', 3)->default('MXN');
            $table->date('started_at')->nullable();
            $table->date('renews_at')->nullable();
            $table->timestamp('last_paid_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
