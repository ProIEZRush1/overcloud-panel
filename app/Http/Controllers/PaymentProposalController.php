<?php

namespace App\Http\Controllers;

use App\Models\PaymentProposal;
use App\Services\BotResponder;
use Illuminate\Http\Request;

class PaymentProposalController extends Controller
{
    public function approve(PaymentProposal $proposal, Request $request)
    {
        $notes = $request->string('notes')->toString() ?: null;
        $proposal->update(['status' => 'approved', 'owner_notes' => $notes, 'resolved_at' => now()]);
        try {
            app(BotResponder::class)->resolveProposal($proposal->fresh(), true, $notes);
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', 'Propuesta aprobada. El bot avisó al cliente. ✅');
    }

    public function reject(PaymentProposal $proposal, Request $request)
    {
        $notes = $request->string('notes')->toString() ?: null;
        $proposal->update(['status' => 'rejected', 'owner_notes' => $notes, 'resolved_at' => now()]);
        try {
            app(BotResponder::class)->resolveProposal($proposal->fresh(), false, $notes);
        } catch (\Throwable $e) {
            report($e);
        }

        return back()->with('success', 'Propuesta rechazada. El bot avisó al cliente. 🙌');
    }
}
