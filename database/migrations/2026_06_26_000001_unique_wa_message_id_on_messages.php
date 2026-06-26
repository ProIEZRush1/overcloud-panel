<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make inbound dedup atomic: a single wa_message_id must map to ONE message (and one reply).
     * The previous plain index let a race in firstOrCreate double-insert and double-reply.
     *
     * Written to be boot-safe: the entrypoint runs `migrate --force` under `set -e`, so this must
     * never throw and crash-loop the whole panel. If the UNIQUE index genuinely can't be created,
     * we log and proceed — the app-level dedup (MessageIngest catches the violation / firstOrCreate)
     * still collapses duplicates, and the index can be added later.
     */
    public function up(): void
    {
        $this->dedupe();

        try {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropIndex(['wa_message_id']); // replace the plain index with a unique one
                $table->unique('wa_message_id');
            });
        } catch (Throwable $e) {
            // A duplicate may have slipped in during the dedup→ALTER window, or the index already
            // exists from a prior partial run. Re-dedupe and try just the unique add once more.
            $this->dedupe();
            try {
                Schema::table('messages', fn (Blueprint $table) => $table->unique('wa_message_id'));
            } catch (Throwable $e2) {
                Log::warning('unique wa_message_id index not added (continuing)', ['e' => $e2->getMessage()]);
            }
        }
    }

    /** Collapse duplicate non-null wa_message_id rows, keeping the earliest (FK-safe: proofs nullOnDelete). */
    private function dedupe(): void
    {
        $dupes = DB::table('messages')
            ->select('wa_message_id', DB::raw('MIN(id) as keep_id'))
            ->whereNotNull('wa_message_id')
            ->groupBy('wa_message_id')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($dupes as $d) {
            DB::table('messages')
                ->where('wa_message_id', $d->wa_message_id)
                ->where('id', '!=', $d->keep_id)
                ->delete();
        }
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            try {
                $table->dropUnique(['wa_message_id']);
            } catch (Throwable $e) {
            }
            $table->index('wa_message_id');
        });
    }
};
