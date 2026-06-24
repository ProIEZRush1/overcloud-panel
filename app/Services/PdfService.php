<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\Spec;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class PdfService
{
    public function renderQuote(Quote $quote): string
    {
        $quote->loadMissing('lead', 'items', 'maintenancePlan');
        $pdf = Pdf::loadView('pdf.quote', [
            'quote' => $quote,
            'company' => config('overcloud.company.name'),
            'brand' => $this->brand(),
        ])->setPaper('letter');

        $path = "pdf/quotes/{$quote->number}.pdf";
        Storage::disk('public')->put($path, $pdf->output());
        $quote->update(['pdf_path' => $path]);

        return $path;
    }

    public function renderSpec(Spec $spec): string
    {
        $spec->loadMissing('lead');
        $pdf = Pdf::loadView('pdf.spec', [
            'spec' => $spec,
            'company' => config('overcloud.company.name'),
            'brand' => $this->brand(),
        ])->setPaper('letter');

        $path = "pdf/specs/{$spec->uuid}.pdf";
        Storage::disk('public')->put($path, $pdf->output());
        $spec->update(['pdf_path' => $path]);

        return $path;
    }

    private function brand(): array
    {
        return [
            'primary' => \App\Models\Setting::get('brand_primary', '#4f46e5'),
            'accent' => \App\Models\Setting::get('brand_accent', '#0ea5e9'),
        ];
    }
}
