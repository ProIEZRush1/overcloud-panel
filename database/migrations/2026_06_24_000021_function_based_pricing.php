<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            // Base recurring maintenance for the platform itself (scales by tier).
            $table->unsignedBigInteger('base_maintenance_cents')->default(0);
        });

        Schema::table('service_features', function (Blueprint $table) {
            $table->string('category')->nullable();
            // Monthly maintenance this function adds — total maintenance scales with complexity.
            $table->unsignedBigInteger('maintenance_cents')->default(0);
            // Which service keys this function is relevant to (null = all).
            $table->json('applies_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $table) => $table->dropColumn('base_maintenance_cents'));
        Schema::table('service_features', fn (Blueprint $table) => $table->dropColumn(['category', 'maintenance_cents', 'applies_to']));
    }
};
