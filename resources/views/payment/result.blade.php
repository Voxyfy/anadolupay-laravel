<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AnadoluPay — Sonuç</title>
    <style>
        :root { color-scheme: light dark; }
        body { font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
               margin: 0; padding: 2.5rem 1.25rem; background: #f6f7f9; color: #17181c; }
        @media (prefers-color-scheme: dark) { body { background: #14151a; color: #e7e8ea; } }
        .wrap { max-width: 760px; margin: 0 auto; }
        .card { background: #fff; border: 1px solid #e4e6ea; border-radius: 12px; padding: 1.5rem; margin-bottom: 1.1rem; }
        @media (prefers-color-scheme: dark) { .card { background: #1c1e24; border-color: #2b2e36; } }
        .banner { border-radius: 12px; padding: 1.1rem 1.25rem; margin-bottom: 1.4rem; }
        .ok { background: #e8f6ec; border: 1px solid #b6e0c2; color: #16603a; }
        .no { background: #fdeaea; border: 1px solid #f3c2c2; color: #8a1c1c; }
        @media (prefers-color-scheme: dark) {
            .ok { background: #16281d; border-color: #2c5138; color: #a7dfba; }
            .no { background: #2c1717; border-color: #5a2626; color: #f3b5b5; }
        }
        h1 { font-size: 1.15rem; margin: 0 0 .2rem; }
        h2 { font-size: .74rem; text-transform: uppercase; letter-spacing: .06em;
             color: #9099a6; margin: 0 0 .7rem; }
        dl { display: grid; grid-template-columns: minmax(140px, auto) 1fr; gap: .45rem 1rem; margin: 0; font-size: .88rem; }
        dt { color: #6b7280; }
        dd { margin: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; word-break: break-all; }
        pre { margin: 0; font-size: .76rem; overflow-x: auto; white-space: pre-wrap; word-break: break-word;
              background: #f2f3f5; padding: .85rem; border-radius: 8px; }
        @media (prefers-color-scheme: dark) { pre { background: #23262e; } }
        a { color: #1f6feb; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="banner {{ $success ? 'ok' : 'no' }}">
        <h1>{{ $message }}</h1>
        <div style="font-size:.85rem;opacity:.85">Sağlayıcı: {{ $driver }}</div>
    </div>

    @if ($detail)
        <div class="card">
            <h2>Sonuç</h2>
            <dl>
                @foreach ($detail as $key => $value)
                    <dt>{{ $key }}</dt>
                    <dd>{{ is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—') }}</dd>
                @endforeach
            </dl>
        </div>
    @endif

    @if ($payload)
        <div class="card">
            <h2>Bankadan gelen veri</h2>
            <pre>{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    @if ($raw)
        <div class="card">
            <h2>Ham yanıt</h2>
            <pre>{{ json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    <p><a href="{{ route('payment.preview') }}">← Yeni ödeme</a></p>
</div>
</body>
</html>
