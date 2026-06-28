<?php

namespace App\Services;

use App\Models\Project;
use App\Models\Quote;
use App\Models\Setting;
use App\Models\Spec;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PdfService
{
    /** Post-delivery "Detalles de tu sistema" doc: URL, qué incluye, cómo acceder y pedir cambios. */
    public function renderDelivery(Project $project): string
    {
        $project->loadMissing('lead');
        $spec = $project->lead?->specs()->latest('id')->first();
        $features = collect($spec?->content['features'] ?? [])
            ->map(fn ($f) => is_array($f) ? trim((string) ($f['name'] ?? '')) : (string) $f)
            ->filter()->values()->all();
        if (empty($features)) {
            $features = collect((array) ($project->brief['requirements'] ?? []))
                ->map(fn ($r) => Str::limit((string) $r, 90))->filter()->take(8)->values()->all();
        }
        $pdf = Pdf::loadView('pdf.delivery', [
            'project' => $project,
            'business' => $project->lead?->company ?: ($project->lead?->name ?: 'tu negocio'),
            'url' => $project->prod_url,
            'features' => $features,
            'comped' => (bool) ($project->brief['comped'] ?? false),
            'company' => config('overcloud.company.name'),
            'brand' => $this->brand(),
            'logo' => $this->logo(),
        ])->setPaper('letter');

        $path = "pdf/delivery/{$project->uuid}.pdf";
        Storage::put($path, $pdf->output());

        return $path;
    }

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
            'primary' => Setting::get('brand_primary', '#7c3aed'),
            'accent' => Setting::get('brand_accent', '#c026d3'),
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
