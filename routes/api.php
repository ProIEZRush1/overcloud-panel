<?php

use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Webhooks from the Node Baileys gateway (shared-token auth, no session/CSRF).
Route::middleware('gateway')->prefix('wa')->group(function () {
    Route::post('inbound', [WhatsAppWebhookController::class, 'inbound']);
    Route::post('status', [WhatsAppWebhookController::class, 'status']);
    Route::post('receipt', [WhatsAppWebhookController::class, 'receipt']);
});
