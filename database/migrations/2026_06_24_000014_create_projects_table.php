<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('quotes')->nullOnDelete();
            $table->foreignId('maintenance_plan_id')->nullable()
                ->constrained('maintenance_plans')->nullOnDelete();
            $table->foreignId('whatsapp_account_id')->nullable()
                ->constrained('whatsapp_accounts')->nullOnDelete();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->nullable();
            $table->string('status')->default('queued')->index();
            $table->json('brief')->nullable();
            $table->string('repo_url')->nullable();
            $table->string('repo_branch')->default('main');
            $table->string('coolify_app_uuid')->nullable();
            $table->string('prod_url')->nullable();
            $table->string('test_url')->nullable();
            $table->string('domain')->nullable();
            $table->string('whatsapp_group_jid')->nullable()->index();
            $table->boolean('maintenance_active')->default(false);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
