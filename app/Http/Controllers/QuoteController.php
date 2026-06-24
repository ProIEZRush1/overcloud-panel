<?php

namespace App\Http\Controllers;

use App\Enums\LeadStage;
use App\Enums\QuoteStatus;
use App\Models\Lead;
use App\Models\Quote;
use App\Services\PaymentService;
use App\Services\PdfService;
use App\Services\WhatsAppGateway;
use Illuminate\Support\Facades\Storage;

class QuoteController extends Controller
{
    public function __construct(private WhatsAppGateway $gateway) {}

    public function pdf(Quote $quote, PdfService $pdf)
    {
        if (! $quote->pdf_path || ! Storage::disk('public')->exists($quote->pdf_path)) {
            $pdf->renderQuote($quote);
            $quote->refresh();
        }

        return response(Storage::disk('public')->get($quote->pdf_path), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$quote->number.'.pdf"',
        ]);
    }

    public function send(Quote $quote, PdfService $pdf)
    {
        if (! $quote->pdf_path) {
            $pdf->renderQuote($quote);
            $quote->refresh();
        }
        $quote->update(['status' => QuoteStatus::Sent, 'sent_at' => now()]);

        $this->toClient($quote->lead, function ($session, $jid) use ($quote) {
            $this->gateway->sendText($session, $jid, "Aquí tienes tu cotización *{$quote->number}* 📄\nTotal: ".\App\Support\Money::format($quote->total_cents, $quote->currency).". ¿La aprobamos? ✅");
            $this->gateway->sendMedia($session, $jid, [
                'base64' => base64_encode(Storage::disk('public')->get($quote->pdf_path)),
                'mimetype' => 'application/pdf', 'fileName' => $quote->number.'.pdf', 'kind' => 'document',
            ]);
        });

        return back()->with('success', "Cotización {$quote->number} enviada.");
    }

    public function accept(Quote $quote, PaymentService $payments)
    {
        $quote->update(['status' => QuoteStatus::Accepted, 'accepted_at' => now()]);
        $quote->lead->update(['stage' => LeadStage::Accepted]);
        $request = $payments->createDeposit($quote);

        $bank = $request->bank_details_snapshot ?? [];
        $this->toClient($quote->lead, function ($session, $jid) use ($request, $bank) {
            $lines = ["¡Gracias! Para iniciar, realiza el anticipo de *".\App\Support\Money::format($request->amount_cents, $request->currency)."* 🙌"];
            if (! empty($bank['bank'])) $lines[] = 'Banco: '.$bank['bank'];
            if (! empty($bank['beneficiary'])) $lines[] = 'Beneficiario: '.$bank['beneficiary'];
            if (! empty($bank['clabe'])) $lines[] = 'CLABE: '.$bank['clabe'];
            if (! empty($bank['account_number'])) $lines[] = 'Cuenta: '.$bank['account_number'];
            $lines[] = 'Referencia: '.$request->reference;
            $lines[] = "\nCuando hagas la transferencia, envíame el comprobante por aquí 📎";
            $this->gateway->sendText($session, $jid, implode("\n", $lines));
        });

        return back()->with('success', 'Cotización aceptada. Se envió la solicitud de pago.');
    }

    private function toClient(Lead $lead, callable $fn): void
    {
        $conversation = $lead->conversations()->with('whatsappAccount')->first();
        if (! $conversation?->whatsappAccount) {
            return;
        }
        try {
            $fn($conversation->whatsappAccount->session_name, $conversation->contact_jid);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
