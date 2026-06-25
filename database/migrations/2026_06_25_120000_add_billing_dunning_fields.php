<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_requests', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable()->after('sent_at');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('delivered_at');
            $table->timestamp('ready_at')->nullable()->after('paused_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_requests', fn (Blueprint $t) => $t->dropColumn('reminded_at'));
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['paused_at', 'ready_at']));
    }
};
