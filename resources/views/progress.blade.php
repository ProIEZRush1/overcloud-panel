<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
@if(!($done ?? false))<meta http-equiv="refresh" content="6">@endif
<title>Tu proyecto en Overcloud</title>
<style>
    :root { --p: {{ $brand['primary'] ?? '#7c3aed' }}; --a: {{ $brand['accent'] ?? '#c026d3' }}; }
    * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    body { margin: 0; background: #0f0a1e; color: #e7e3f4; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
    .card { width: 100%; max-width: 520px; background: #181230; border: 1px solid #2a2150; border-radius: 20px; padding: 32px 28px; box-shadow: 0 20px 60px rgba(0,0,0,.4); }
    .brand { font-weight: 800; font-size: 22px; background: linear-gradient(90deg,var(--p),var(--a)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    h1 { font-size: 19px; margin: 14px 0 4px; }
    .sub { color: #a99fce; font-size: 13px; margin-bottom: 22px; }
    .bar { height: 10px; background: #2a2150; border-radius: 999px; overflow: hidden; margin-bottom: 24px; }
    .fill { height: 100%; width: {{ $pct }}%; background: linear-gradient(90deg,var(--p),var(--a)); border-radius: 999px; transition: width .6s ease; }
    .step { display: flex; align-items: center; gap: 12px; padding: 11px 0; border-bottom: 1px solid #221a44; font-size: 15px; }
    .step:last-child { border-bottom: 0; }
    .dot { width: 24px; height: 24px; border-radius: 50%; flex: 0 0 24px; display: flex; align-items: center; justify-content: center; font-size: 13px; }
    .done .dot { background: var(--p); color: #fff; }
    .done { color: #e7e3f4; }
    .current .dot { background: transparent; border: 3px solid var(--a); border-top-color: transparent; animation: spin 1s linear infinite; }
    .current { color: #fff; font-weight: 600; }
    .pending { color: #6b6390; }
    .pending .dot { background: #221a44; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .live { margin-top: 24px; padding: 16px; background: linear-gradient(90deg,rgba(124,58,237,.18),rgba(192,38,211,.18)); border: 1px solid #3a2f6a; border-radius: 14px; text-align: center; }
    .live a { display: inline-block; margin-top: 8px; background: linear-gradient(90deg,var(--p),var(--a)); color: #fff; text-decoration: none; padding: 11px 22px; border-radius: 10px; font-weight: 700; }
    .foot { text-align: center; color: #6b6390; font-size: 12px; margin-top: 22px; }
    .pulse { display:inline-block; width:8px; height:8px; border-radius:50%; background: var(--a); margin-right:6px; animation: pulse 1.4s infinite; }
    @keyframes pulse { 0%,100%{opacity:.3} 50%{opacity:1} }
</style>
</head>
<body>
    <div class="card">
        <div class="brand">Overcloud</div>
        <h1>{{ $title }}</h1>
        <div class="sub">@if($done) ¡Tu sistema está listo! 🎉 @elseif($failed ?? false)<span class="pulse"></span>Estamos afinando un detalle — retomamos en un momento @else<span class="pulse"></span>Construyendo tu sistema en tiempo real @endif</div>

        <div class="bar"><div class="fill"></div></div>

        @foreach($steps as $i => $label)
            @php $cls = $i < $current ? 'done' : ($i === $current && !$done ? 'current' : ($done ? 'done' : 'pending')); @endphp
            <div class="step {{ $cls }}">
                <span class="dot">@if($cls==='done')✓@elseif($cls==='current')@else{{ $i+1 }}@endif</span>
                <span>{{ $label }}</span>
            </div>
        @endforeach

        @if($done && $url)
            <div class="live">
                🚀 Tu sistema ya está en línea
                <div><a href="{{ $url }}">Abrir mi sistema</a></div>
            </div>
        @endif

        <div class="foot">Esta página se actualiza sola · {{ $business }} · Overcloud</div>
    </div>
</body>
</html>
