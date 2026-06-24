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
            'logo' => $this->logo(),
            'mark' => $this->logo('mark.png'),
        ])->setPaper('letter');

        $path = "pdf/quotes/{$quote->number}.pdf";
        Storage::put($path, $pdf->output());
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
            'logo' => $this->logo(),
            'mark' => $this->logo('mark.png'),
        ])->setPaper('letter');

        $path = "pdf/specs/{$spec->uuid}.pdf";
        Storage::put($path, $pdf->output());
        $spec->update(['pdf_path' => $path]);

        return $path;
    }

    private function brand(): array
    {
        return [
            'primary' => \App\Models\Setting::get('brand_primary', '#7c3aed'),
            'accent' => \App\Models\Setting::get('brand_accent', '#c026d3'),
        ];
    }

    /** Brand image as a base64 data URI so dompdf embeds it reliably. */
    private function logo(string $file = 'wordmark.png'): ?string
    {
        $path = public_path('brand/'.$file);

        return is_file($path)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($path))
            : null;
    }
}
