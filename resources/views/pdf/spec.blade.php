<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    * { font-family: DejaVu Sans, sans-serif; }
    body { margin: 0; color: #1f2937; font-size: 12px; }
    .bar { background: {{ $brand['primary'] }}; color: #fff; padding: 28px 40px; }
    .bar h1 { margin: 0; font-size: 24px; }
    .bar .sub { opacity: .85; font-size: 12px; margin-top: 4px; }
    .wrap { padding: 28px 40px; }
    h2 { color: {{ $brand['primary'] }}; font-size: 14px; border-bottom: 2px solid #eceef1; padding-bottom: 6px; margin-top: 22px; }
    ul { margin: 8px 0; padding-left: 18px; }
    li { margin: 4px 0; }
    .muted { color: #6b7280; }
    .pill { display:inline-block; background:#f3f4f6; padding:3px 9px; border-radius:8px; margin:2px; font-size:11px; }
    .foot { margin-top: 30px; text-align: center; color: #9ca3af; font-size: 10px; }
</style>
</head>
<body>
    <div class="bar">
        <h1>{{ $spec->title }}</h1>
        <div class="sub">{{ $company }} · Documento de alcance v{{ $spec->version }} · {{ $spec->created_at->format('d/m/Y') }}</div>
    </div>
    <div class="wrap">
        <div class="muted">Cliente: <strong>{{ $spec->lead->name ?? 'Cliente' }}</strong>
            @if($spec->lead->company) · {{ $spec->lead->company }} @endif
            · {{ $spec->lead->phone }}</div>

        @if($spec->summary)
            <h2>Resumen</h2>
            <p>{{ $spec->summary }}</p>
        @endif

        <h2>Páginas / Secciones</h2>
        <div>@foreach(($spec->content['pages'] ?? []) as $p)<span class="pill">{{ $p }}</span>@endforeach</div>

        <h2>Funcionalidades</h2>
        <ul>@foreach(($spec->content['features'] ?? []) as $f)<li>{{ $f }}</li>@endforeach</ul>

        <h2>Entregables</h2>
        <ul>@foreach(($spec->content['deliverables'] ?? []) as $d)<li>{{ $d }}</li>@endforeach</ul>

        <table style="width:100%; margin-top:18px;">
            <tr>
                <td><span class="muted">Idiomas:</span> {{ implode(', ', $spec->content['languages'] ?? ['es']) }}</td>
                <td><span class="muted">Tiempo estimado:</span> {{ $spec->content['timeline_days'] ?? '—' }} días</td>
            </tr>
        </table>

        @if($spec->content['notes'] ?? null)
            <h2>Notas</h2>
            <p>{{ $spec->content['notes'] }}</p>
        @endif

        <div class="foot">{{ $company }} · Confirma este alcance por WhatsApp para recibir tu cotización.</div>
    </div>
</body>
</html>
