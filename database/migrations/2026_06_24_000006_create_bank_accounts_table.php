<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('label');                            // e.g. "BBVA principal"
            $table->string('bank')->nullable();
            $table->string('beneficiary')->nullable();
            $table->string('account_number')->nullable();
            $table->string('clabe')->nullable();                // MX interbank key
            $table->char('currency', 3)->default('MXN');
            $table->text('instructions')->nullable();           // extra notes sent to the client
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
