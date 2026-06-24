<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('whatsapp_account_id')->nullable()
                ->constrained('whatsapp_accounts')->nullOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()
                ->constrained('maintenance_plans')->nullOnDelete();
            $table->foreignId('assigned_to_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('name')->nullable();
            $table->string('phone')->index();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->string('source')->default('whatsapp');

            $table->string('stage')->default('new')->index();
            $table->string('service_type')->nullable();         // free text if not in catalog
            $table->text('summary')->nullable();
            $table->json('requirements')->nullable();           // structured captured requirements
            $table->unsignedInteger('pages')->nullable();
            $table->json('languages')->nullable();              // ['es'] / ['es','en']
            $table->string('budget_hint')->nullable();
            $table->unsignedTinyInteger('deposit_percent')->default(50);
            $table->unsignedTinyInteger('score')->nullable();   // 0-100 qualification score
            $table->string('locale')->default('es');
            $table->text('notes')->nullable();
            $table->timestamp('last_contact_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
