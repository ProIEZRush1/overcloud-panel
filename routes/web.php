<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\LeadController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentProposalController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\WhatsAppAccountController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'))->name('home');

// Public live build-progress page a client opens by link (no auth).
Route::get('/progreso/{uuid}', [ProgressController::class, 'show'])->name('progress');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // WhatsApp numbers
    Route::get('whatsapp', [WhatsAppAccountController::class, 'index'])->name('whatsapp.index');
    Route::post('whatsapp', [WhatsAppAccountController::class, 'store'])->name('whatsapp.store');
    Route::post('whatsapp/{account}/connect', [WhatsAppAccountController::class, 'connect'])->name('whatsapp.connect');
    Route::get('whatsapp/{account}/status', [WhatsAppAccountController::class, 'status'])->name('whatsapp.status');
    Route::post('whatsapp/{account}/logout', [WhatsAppAccountController::class, 'logout'])->name('whatsapp.logout');
    Route::delete('whatsapp/{account}', [WhatsAppAccountController::class, 'destroy'])->name('whatsapp.destroy');

    // Inbox
    Route::get('inbox', [ConversationController::class, 'index'])->name('inbox.index');
    Route::get('inbox/{conversation}', [ConversationController::class, 'show'])->name('inbox.show');
    Route::post('inbox/{conversation}/reply', [ConversationController::class, 'reply'])->name('inbox.reply');
    Route::post('inbox/{conversation}/toggle-bot', [ConversationController::class, 'toggleBot'])->name('inbox.toggle');

    // Leads / funnel
    Route::get('leads', [LeadController::class, 'index'])->name('leads.index');
    Route::get('leads/{lead}', [LeadController::class, 'show'])->name('leads.show');
    Route::put('leads/{lead}', [LeadController::class, 'update'])->name('leads.update');
    Route::post('leads/{lead}/spec', [LeadController::class, 'spec'])->name('leads.spec');
    Route::post('leads/{lead}/quote', [LeadController::class, 'quote'])->name('leads.quote');

    // Quotes
    Route::get('quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->name('quotes.pdf');

    // Authenticated file streaming (spec PDFs, payment proofs, WhatsApp media) — never public.
    Route::get('files/specs/{spec}', [FileController::class, 'spec'])->name('files.spec');
    Route::get('files/proofs/{proof}', [FileController::class, 'proof'])->name('files.proof');
    Route::get('files/media/{message}', [FileController::class, 'media'])->name('files.media');
    Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
    Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');

    // Payments
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments/{payment}/verify', [PaymentController::class, 'verify'])->name('payments.verify');
    Route::post('payments/{payment}/reject', [PaymentController::class, 'reject'])->name('payments.reject');
    // Alternative payment proposals: owner approves/rejects, the bot tells the client.
    Route::post('payment-proposals/{proposal}/approve', [PaymentProposalController::class, 'approve'])->name('proposals.approve');
    Route::post('payment-proposals/{proposal}/reject', [PaymentProposalController::class, 'reject'])->name('proposals.reject');

    // Catalog / settings
    Route::get('catalog', [CatalogController::class, 'index'])->name('catalog.index');
    Route::put('catalog/services/{service}', [CatalogController::class, 'updateService'])->name('catalog.service');
    Route::put('catalog/banks/{bank}', [CatalogController::class, 'updateBank'])->name('catalog.bank');
    Route::put('catalog/settings', [CatalogController::class, 'updateSettings'])->name('catalog.settings');
});

require __DIR__.'/settings.php';
