<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_client_id')->nullable();   // Dev-Business clients.id
        });
        Schema::table('quotes', function (Blueprint $table) {
            $table->unsignedBigInteger('crm_project_id')->nullable();  // Dev-Business projects.id
        });
    }

    public function down(): void
    {
        Schema::table('leads', fn (Blueprint $table) => $table->dropColumn('crm_client_id'));
        Schema::table('quotes', fn (Blueprint $table) => $table->dropColumn('crm_project_id'));
    }
};
