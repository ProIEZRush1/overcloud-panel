@php use App\Support\Money; @endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { margin: 0; color: #1f2937; font-size: 12px; }
    .bar { background: #ffffff; padding: 22px 40px 16px; border-bottom: 3px solid {{ $brand['primary'] }}; }
    .bar img { height: 36px; }
    .bar .num { font-weight: bold; font-size: 13px; color: {{ $brand['primary'] }}; }
    .bar .sub { color: #6b7280; font-size: 11px; margin-top: 3px; }
    .wrap { padding: 28px 40px; }
    .row { width: 100%; }
    .row td { vertical-align: top; }
    .muted { color: #6b7280; }
    .label { color: #6b7280; font-size: 10px; text-transform: uppercase; letter-spacing: .5px; }
    table.items { width: 100%; border-collapse: collapse; margin-top: 18px; }
    table.items th { text-align: left; background: #f3f4f6; padding: 9px 10px; font-size: 11px; color: #374151; }
    table.items td { padding: 9px 10px; border-bottom: 1px solid #eceef1; }
    .right { text-align: right; }
    .totals { width: 46%; margin-left: 54%; margin-top: 14px; }
    .totals td { padding: 5px 10px; }
    .totals .grand { font-size: 15px; font-weight: bold; color: {{ $brand['primary'] }}; border-top: 2px solid {{ $brand['primary'] }}; }
    .chip { display: inline-block; background: {{ $brand['accent'] }}; color:#fff; padding: 4px 10px; border-radius: 10px; font-size: 11px; }
    .terms { margin-top: 26px; padding: 14px 16px; background: #f9fafb; border-left: 3px solid {{ $brand['accent'] }}; }
    .maint { margin-top: 22px; padding: 16px 18px; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; }
    .maint .mt { font-size: 13px; font-weight: bold; color: {{ $brand['primary'] }}; }
    .maint .mt .apart { font-weight: normal; font-size: 11px; color: #6b7280; }
    .maint p { margin: 8px 0 12px; line-height: 1.5; color: #374151; }
    .maint .price { width: 100%; border-top: 1px dashed #c4b5fd; padding-top: 10px; }
    .maint .price td { padding: 2px 0; }
    .maint .price .amt { font-size: 15px; font-weight: bold; color: {{ $brand['primary'] }}; }
    .foot { margin-top: 30px; text-align: center; color: #9ca3af; font-size: 10px; }
</style>
</head>
<body>
    <div class="bar">
        <table style="width:100%"><tr>
            <td style="vertical-align:middle;">
                @if($logo)<img src="{{ $logo }}" alt="{{ $company }}">@else<span class="num" style="font-size:24px;">{{ $company }}</span>@endif
            </td>
            <td class="right" style="vertical-align:middle;">
                <div class="num">Cotización {{ $quote->number }}</div>
                <div class="sub">{{ $quote->created_at->format('d/m/Y') }}</div>
            </td>
        </tr></table>
    </div>

    <div class="wrap">
        <table class="row">
            <tr>
                <td style="width:55%">
                    <div class="label">Cliente</div>
                    <div style="font-size:14px; font-weight:bold;">{{ $quote->lead->name ?? 'Cliente' }}</div>
                    @if($quote->lead->company)<div class="muted">{{ $quote->lead->company }}</div>@endif
                    <div class="muted">{{ $quote->lead->phone }}</div>
                    @if($quote->lead->email)<div class="muted">{{ $quote->lead->email }}</div>@endif
                </td>
                <td class="right">
                    <div class="label">Válida hasta</div>
                    <div style="font-weight:bold;">{{ optional($quote->valid_until)->format('d/m/Y') ?? '—' }}</div>
                    <div style="margin-top:8px;"><span class="chip">{{ $quote->status->label() }}</span></div>
                </td>
            </tr>
        </table>

        <table class="items">
            <thead>
                <tr>
                    <th>Concepto</th>
                    <th class="right" style="width:60px;">Cant.</th>
                    <th class="right" style="width:120px;">Precio</th>
                    <th class="right" style="width:120px;">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach($quote->items as $item)
                    <tr>
                        <td>{{ $item->description }}</td>
                        <td class="right">{{ $item->quantity }}</td>
                        <td class="right">{{ Money::format($item->unit_price_cents, $quote->currency) }}</td>
                        <td class="right">{{ Money::format($item->total_cents, $quote->currency) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td class="muted">Subtotal</td><td class="right">{{ Money::format($quote->subtotal_cents, $quote->currency) }}</td></tr>
            @if($quote->discount_cents > 0)
                <tr><td class="muted">Descuento</td><td class="right">- {{ Money::format($quote->discount_cents, $quote->currency) }}</td></tr>
            @endif
            <tr><td class="grand">Total del proyecto</td><td class="right grand">{{ Money::format($quote->total_cents, $quote->currency) }}</td></tr>
            <tr><td colspan="2" class="muted" style="font-size:10px; text-align:right;">Pago único del proyecto</td></tr>
            @if($quote->deposit_cents > 0)
                <tr><td class="muted">Anticipo ({{ $quote->deposit_percent }}%)</td><td class="right">{{ Money::format($quote->deposit_cents, $quote->currency) }}</td></tr>
            @endif
        </table>

        @if($quote->maintenance_monthly_cents > 0)
            <div class="maint">
                <div class="mt">Mantenimiento mensual <span class="apart">— servicio aparte del proyecto</span></div>
                <p>
                    El <strong>proyecto</strong> de arriba es un <strong>pago único</strong>. El <strong>mantenimiento</strong> es un
                    servicio <strong>mensual e independiente</strong> que mantiene tu sitio o app <strong>en línea</strong> (hosting) e
                    incluye <strong>cambios y soporte</strong> cuando los necesites. No forma parte del precio del proyecto y se cobra
                    por separado, mes con mes. Si más adelante prefieres solo mantener el sitio publicado, también hay un plan
                    de <strong>solo hosting</strong> más económico — solo pídelo.
                </p>
                <table class="price">
                    <tr>
                        <td>Plan de mantenimiento (hosting + cambios y soporte)</td>
                        <td class="right"><span class="amt">{{ Money::format($quote->maintenance_monthly_cents, $quote->currency) }}</span> <span class="muted">/ mes</span></td>
                    </tr>
                </table>
            </div>
        @endif

        @if($quote->terms)
            <div class="terms"><strong>Términos:</strong> {{ $quote->terms }}</div>
        @endif

        <div class="foot">Gracias por confiar en {{ $company }} · Esta cotización fue generada automáticamente.</div>
    </div>
</body>
</html>
