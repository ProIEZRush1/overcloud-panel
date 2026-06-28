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
    .wrap { padding: 28px 40px; }
    .h1 { font-size: 20px; font-weight: bold; color: {{ $brand['primary'] }}; margin: 0 0 2px; }
    .muted { color: #6b7280; }
    .url { display: block; margin: 14px 0; padding: 14px 16px; background: #f5f3ff; border: 1px solid #ddd6fe; border-radius: 8px; }
    .url .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; color: #6b7280; }
    .url a { font-size: 15px; font-weight: bold; color: {{ $brand['primary'] }}; text-decoration: none; word-break: break-all; }
    h2 { font-size: 13px; color: #111827; margin: 22px 0 8px; border-bottom: 1px solid #eceef1; padding-bottom: 5px; }
    ul { margin: 6px 0; padding-left: 18px; line-height: 1.6; }
    .step { margin: 6px 0; line-height: 1.5; }
    .box { padding: 12px 16px; background: #f9fafb; border-left: 3px solid {{ $brand['accent'] }}; border-radius: 4px; margin-top: 8px; }
    .foot { margin-top: 30px; text-align: center; color: #9ca3af; font-size: 10px; }
</style>
</head>
<body>
    <div class="bar">
        <table style="width:100%"><tr>
            <td style="vertical-align:middle;">
                @if($logo)<img src="{{ $logo }}" alt="{{ $company }}">@else<span class="num" style="font-size:24px;">{{ $company }}</span>@endif
            </td>
            <td style="text-align:right; vertical-align:middle;">
                <div class="num">Detalles de tu sistema</div>
                <div class="muted">{{ now()->format('d/m/Y') }}</div>
            </td>
        </tr></table>
    </div>

    <div class="wrap">
        <div class="h1">{{ $project->name ?: $business }}</div>
        <div class="muted">Proyecto de {{ $business }} · entregado por {{ $company }}</div>

        @if($url)
            <div class="url">
                <span class="lbl">Tu sistema en línea</span>
                <a href="{{ $url }}">{{ $url }}</a>
            </div>
        @endif

        <h2>Cómo acceder</h2>
        <div class="step">1. Abre el enlace de arriba en cualquier navegador (celular o computadora).</div>
        <div class="step">2. Ese es tu sistema, funcionando en línea — no necesitas instalar nada.</div>
        <div class="step">3. Guárdalo en tus favoritos o agrégalo a la pantalla de inicio de tu celular para abrirlo como una app.</div>

        @if(!empty($features))
            <h2>Qué incluye</h2>
            <ul>
                @foreach($features as $f)
                    <li>{{ $f }}</li>
                @endforeach
            </ul>
        @endif

        <h2>Cómo administrarlo y pedir cambios</h2>
        <div class="step">Para cualquier cambio, ajuste, contenido nuevo o función adicional, escríbenos por WhatsApp en tu <strong>grupo de proyecto</strong> de Overcloud. Ahí coordinamos todo y aplicamos los cambios a tu sistema. Ese grupo existe justamente para mantener, personalizar y hacer crecer tu sistema.</div>
        <div class="box"><strong>Tip:</strong> el grupo de proyecto es tu canal directo para todo lo de tu sistema: cambios de contenido, nuevas secciones o módulos, dudas y soporte.</div>

        <h2>Soporte y continuidad</h2>
        @if($comped)
            <div class="step">Este proyecto es una <strong>cortesía</strong>, sin costo. Cuenta con nosotros para lo que necesites.</div>
        @else
            <div class="step">Tu <strong>mantenimiento mensual</strong> mantiene tu sistema en línea (hosting) e incluye cambios y soporte continuos. Es un servicio aparte y recurrente.</div>
        @endif

        <div class="foot">Gracias por confiar en {{ $company }} · Documento generado automáticamente.</div>
    </div>
</body>
</html>
