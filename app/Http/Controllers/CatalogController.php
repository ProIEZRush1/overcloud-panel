<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\MaintenancePlan;
use App\Models\Service;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CatalogController extends Controller
{
    public function index()
    {
        return Inertia::render('catalog/Index', [
            'services' => Service::orderBy('sort_order')->get()->map(fn (Service $s) => [
                'id' => $s->id, 'key' => $s->key, 'name' => $s->name, 'category' => $s->category,
                'base_price' => \App\Support\Money::pesos($s->base_price_cents),
                'per_page' => \App\Support\Money::pesos($s->per_page_price_cents),
                'per_language' => \App\Support\Money::pesos($s->per_language_price_cents),
                'included_pages' => $s->included_pages, 'is_active' => $s->is_active,
            ]),
            'plans' => MaintenancePlan::orderBy('sort_order')->get()->map(fn (MaintenancePlan $p) => [
                'id' => $p->id, 'name' => $p->name, 'monthly' => \App\Support\Money::pesos($p->monthly_price_cents),
                'included' => $p->included, 'is_active' => $p->is_active,
            ]),
            'banks' => BankAccount::orderBy('sort_order')->get(['id', 'label', 'bank', 'beneficiary', 'account_number', 'clabe', 'is_default', 'is_active']),
            'settings' => [
                'company_name' => Setting::get('company_name'),
                'brand_primary' => Setting::get('brand_primary'),
                'default_deposit_percent' => Setting::get('default_deposit_percent'),
                'quote_valid_days' => Setting::get('quote_valid_days'),
            ],
        ]);
    }

    public function updateService(Request $request, Service $service)
    {
        $data = $request->validate([
            'base_price' => 'required|numeric|min:0', 'per_page' => 'nullable|numeric|min:0',
            'per_language' => 'nullable|numeric|min:0', 'included_pages' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
        ]);
        $service->update([
            'base_price_cents' => \App\Support\Money::toCents($data['base_price']),
            'per_page_price_cents' => \App\Support\Money::toCents($data['per_page'] ?? 0),
            'per_language_price_cents' => \App\Support\Money::toCents($data['per_language'] ?? 0),
            'included_pages' => $data['included_pages'] ?? $service->included_pages,
            'is_active' => $data['is_active'] ?? $service->is_active,
        ]);

        return back()->with('success', 'Servicio actualizado.');
    }

    public function updateBank(Request $request, BankAccount $bank)
    {
        $data = $request->validate([
            'label' => 'required|string|max:60', 'bank' => 'nullable|string|max:60',
            'beneficiary' => 'nullable|string|max:120', 'account_number' => 'nullable|string|max:40',
            'clabe' => 'nullable|string|max:40', 'is_default' => 'boolean', 'is_active' => 'boolean',
        ]);
        if ($data['is_default'] ?? false) {
            BankAccount::where('id', '!=', $bank->id)->update(['is_default' => false]);
        }
        $bank->update($data);

        return back()->with('success', 'Cuenta bancaria actualizada.');
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'nullable|string|max:60', 'brand_primary' => 'nullable|string|max:9',
            'default_deposit_percent' => 'nullable|integer|min:0|max:100', 'quote_valid_days' => 'nullable|integer|min:1',
        ]);
        foreach ($data as $key => $value) {
            if ($value !== null) {
                Setting::put($key, $value, in_array($key, ['company_name', 'brand_primary']) ? 'branding' : 'pricing');
            }
        }

        return back()->with('success', 'Configuración guardada.');
    }
}
