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
    .right { text-align: right; }
    .wrap { padding: 28px 40px; }
    h2 { color: {{ $brand['primary'] }}; font-size: 14px; border-bottom: 2px solid #eceef1; padding-bottom: 6px; margin-top: 22px; }
    ul { margin: 8px 0; padding-left: 18px; }
    li { margin: 4px 0; }
    .muted { color: #6b7280; }
    .pill { display:inline-block; background:#f3f4f6; padding:3px 9px; border-radius:8px; margin:2px; font-size:11px; }
    .item { margin: 7px 0; line-height: 1.4; }
    .item b { color: #111827; }
    ol { margin: 8px 0; padding-left: 20px; }
    p { line-height: 1.5; }
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
                <div class="num">Documento de alcance v{{ $spec->version }}</div>
                <div class="sub">{{ $spec->created_at->format('d/m/Y') }}</div>
            </td>
        </tr></table>
    </div>
    <div class="wrap">
        <h1 style="color:{{ $brand['primary'] }}; font-size:20px; margin:0 0 6px;">{{ $spec->title }}</h1>
        <div class="muted">Cliente: <strong>{{ $spec->lead->name ?? 'Cliente' }}</strong>
            @if($spec->lead->company) · {{ $spec->lead->company }} @endif
            · {{ $spec->lead->phone }}</div>

        @if($spec->content['overview'] ?? null)
            <h2>Resumen ejecutivo</h2>
            <p>{{ $spec->content['overview'] }}</p>
        @endif

        @if($spec->content['objectives'] ?? null)
            <h2>Objetivos del proyecto</h2>
            <ul>@foreach($spec->content['objectives'] as $o)<li>{{ $o }}</li>@endforeach</ul>
        @endif

        <h2>Páginas y secciones</h2>
        @foreach(($spec->content['pages'] ?? []) as $p)
            <div class="item"><b>{{ is_array($p) ? ($p['name'] ?? '') : $p }}</b>@if(is_array($p) && ! empty($p['desc'])) — {{ $p['desc'] }}@endif</div>
        @endforeach

        <h2>Funcionalidades</h2>
        @foreach(($spec->content['features'] ?? []) as $f)
            <div class="item"><b>{{ is_array($f) ? ($f['name'] ?? '') : $f }}</b>@if(is_array($f) && ! empty($f['desc'])) — {{ $f['desc'] }}@endif</div>
        @endforeach

        <h2>Entregables</h2>
        <ul>@foreach(($spec->content['deliverables'] ?? []) as $d)<li>{{ $d }}</li>@endforeach</ul>

        @if($spec->content['technical'] ?? null)
            <h2>Incluye (aspectos técnicos)</h2>
            <ul>@foreach($spec->content['technical'] as $t)<li>{{ $t }}</li>@endforeach</ul>
        @endif

        @if($spec->content['process'] ?? null)
            <h2>Proceso de trabajo</h2>
            <ol>@foreach($spec->content['process'] as $p)<li>{{ $p }}</li>@endforeach</ol>
        @endif

        <table style="width:100%; margin-top:18px;">
            <tr>
                <td><span class="muted">Idiomas:</span> {{ implode(', ', $spec->content['languages'] ?? ['es']) }}</td>
                <td><span class="muted">Tiempo estimado:</span> {{ $spec->content['timeline_days'] ?? '—' }} días</td>
            </tr>
        </table>

        @if($spec->content['out_of_scope'] ?? null)
            <h2>No incluye</h2>
            <ul>@foreach($spec->content['out_of_scope'] as $o)<li>{{ $o }}</li>@endforeach</ul>
        @endif

        @if($spec->content['notes'] ?? null)
            <h2>Notas adicionales</h2>
            <p>{{ $spec->content['notes'] }}</p>
        @endif

        <div class="foot">{{ $company }} · Confirma este alcance por WhatsApp para recibir tu cotización.</div>
    </div>
</body>
</html>
