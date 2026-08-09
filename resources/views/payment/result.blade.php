{{--
    Ödeme sonucu.

    Bu sayfa bankanın POST'u sonrası açılır; Livewire/Vite yaşam döngüsüne
    bağlı olmasın diye kendi kabuğunu ve stilini taşır.
--}}
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AnadoluPay — Ödeme sonucu</title>
    <link rel="icon" href="{{ asset('img/anadolupay.png') }}">
    <style>
        :root {
            color-scheme: light dark;
            --bg: #fff; --fg: #0a0a0a; --muted: #737373; --line: rgba(10,10,10,.10);
            --panel: #fff; --code-bg: #f5f5f5;
            --ok-bg: #f0fdf4; --ok-line: #bbf7d0; --ok-fg: #14532d;
            --no-bg: #fef2f2; --no-line: #fecaca; --no-fg: #7f1d1d;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0a0a0a; --fg: #fafafa; --muted: #a3a3a3; --line: rgba(255,255,255,.10);
                --panel: rgba(255,255,255,.02); --code-bg: rgba(255,255,255,.04);
                --ok-bg: rgba(34,197,94,.10); --ok-line: rgba(34,197,94,.25); --ok-fg: #bbf7d0;
                --no-bg: rgba(239,68,68,.10); --no-line: rgba(239,68,68,.25); --no-fg: #fecaca;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--fg);
               font: 15px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
               -webkit-font-smoothing: antialiased; }
        .bar { position: sticky; top: 0; z-index: 10; border-bottom: 1px solid var(--line);
               background: color-mix(in srgb, var(--bg) 80%, transparent); backdrop-filter: blur(16px); }
        .bar-in { max-width: 880px; margin: 0 auto; padding: 0 1.5rem; height: 64px;
                  display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
        .logo { display: flex; align-items: center; gap: .6rem; color: inherit; text-decoration: none; }
        .logo svg { width: 26px; height: 26px; fill: currentColor; }
        .logo b { font-size: 14px; font-weight: 600; letter-spacing: -.01em; }
        .bar-note { font: 500 13px/1 ui-sans-serif, system-ui, sans-serif; color: var(--muted); }

        main { max-width: 880px; margin: 0 auto; padding: 0 1.5rem 6rem; }
        .hero { padding: 3.5rem 0 2.5rem; }
        .pill { display: inline-flex; align-items: center; gap: .5rem; border-radius: 999px;
                padding: .35rem .8rem; font-size: .78rem; font-weight: 600; letter-spacing: .01em;
                border: 1px solid; }
        .pill.ok { background: var(--ok-bg); border-color: var(--ok-line); color: var(--ok-fg); }
        .pill.no { background: var(--no-bg); border-color: var(--no-line); color: var(--no-fg); }
        h1 { margin: 1.1rem 0 0; font-size: clamp(2rem, 5vw, 3rem); font-weight: 600;
             letter-spacing: -.025em; line-height: 1.1; }
        .lede { margin: .75rem 0 0; font-size: 1.05rem; color: var(--muted); }

        .toolbar { margin: 0 0 2rem; }
        .panel { border: 1px solid var(--line); border-radius: 24px; background: var(--panel);
                 padding: 1.75rem; margin-bottom: 1.25rem; }
        .head { display: flex; align-items: baseline; justify-content: space-between; gap: 1rem; margin-bottom: 1.1rem; }
        h2 { margin: 0; font-size: 11px; font-weight: 600; text-transform: uppercase;
             letter-spacing: .16em; color: var(--muted); }

        dl { margin: 0; border: 1px solid var(--line); border-radius: 16px; overflow: hidden; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: 1.5rem;
               padding: .7rem 1rem; }
        .row + .row { border-top: 1px solid var(--line); }
        dt { color: var(--muted); font-size: .88rem; }
        dd { margin: 0; font: 12px/1.5 ui-monospace, SFMono-Regular, Menlo, monospace;
             word-break: break-all; text-align: right; }

        pre { margin: 0; padding: 1rem; border-radius: 16px; background: var(--code-bg);
              font: 11.5px/1.65 ui-monospace, SFMono-Regular, Menlo, monospace;
              white-space: pre-wrap; word-break: break-word; overflow-x: auto; }

        button.copy { font: 500 12px/1 ui-sans-serif, system-ui, sans-serif; cursor: pointer;
                      padding: .5rem .85rem; border-radius: 10px; white-space: nowrap;
                      border: 1px solid var(--line); background: transparent; color: var(--muted);
                      transition: color .15s, border-color .15s, background .15s; }
        button.copy:hover { color: var(--fg); border-color: color-mix(in srgb, var(--fg) 30%, transparent); }
        button.copy.done { color: var(--ok-fg); border-color: var(--ok-line); background: var(--ok-bg); }
        .toolbar button.copy { padding: .8rem 1.4rem; font-size: 14px; border-radius: 14px;
                               background: var(--fg); color: var(--bg); border-color: transparent; }
        .toolbar button.copy:hover { opacity: .85; color: var(--bg); }
        .toolbar button.copy.done { background: var(--ok-fg); color: var(--bg); }

        a.back { display: inline-block; margin-top: 1.5rem; color: var(--muted);
                 text-decoration: none; font-size: .9rem; }
        a.back:hover { color: var(--fg); }
    </style>
</head>
<body>

<div class="bar">
    <div class="bar-in">
        {{-- Marka görseli 1280×640; şeride sığmadığı için burada işaret kullanılıyor. --}}
        <a href="{{ route('payment.preview') }}" class="logo">
            <x-app-logo-icon />
            <b>AnadoluPay</b>
        </a>
        <span class="bar-note">{{ $driver }}</span>
    </div>
</div>

<main>
    <div class="hero">
        <span class="pill {{ $success ? 'ok' : 'no' }}">
            {{ $success ? 'Onaylandı' : 'Alınamadı' }}
        </span>
        <h1>{{ $message }}</h1>
        <p class="lede">
            Sonuç <strong>{{ $driver }}</strong> driver'ından geldi ve imzası paket tarafından doğrulandı.
        </p>
    </div>

    {{--
        Hata ayıklarken sayfadaki her şeyi tek parça paylaşmak gerekiyor;
        blokları tek tek kopyalamak yerine hepsini tek JSON veren bir düğme var.
    --}}
    @php
        $rapor = json_encode([
            'driver' => $driver,
            'success' => $success,
            'message' => $message,
            'detail' => $detail ?: null,
            'payload' => $payload ?: null,
            'raw' => $raw ?: null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @endphp

    <div class="toolbar">
        <button type="button" class="copy" data-copy="rapor">Tümünü JSON olarak kopyala</button>
    </div>

    <script type="application/json" id="rapor">{!! $rapor !!}</script>

    @if ($detail)
        <div class="panel">
            <div class="head"><h2>Sonuç</h2></div>
            <dl>
                {{--
                    `detail` yalnızca skaler taşımayabilir: imza/hata
                    yollarında istisnanın bağlamı geliyor ve içinde dizi
                    olabiliyor. Blade dizgeye zorladığı için ekran çöküyordu.
                --}}
                @foreach ($detail as $key => $value)
                    <div class="row">
                        <dt>{{ $key }}</dt>
                        <dd>
                            @if (is_bool($value))
                                {{ $value ? 'true' : 'false' }}
                            @elseif (is_scalar($value))
                                {{ $value }}
                            @elseif ($value === null)
                                —
                            @else
                                {{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>
        </div>
    @endif

    @if ($payload)
        <div class="panel">
            <div class="head">
                <h2>Sağlayıcıdan gelen veri</h2>
                <button type="button" class="copy" data-copy="payload">Kopyala</button>
            </div>
            <pre id="payload">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    @if ($raw)
        <div class="panel">
            <div class="head">
                <h2>Ham yanıt</h2>
                <button type="button" class="copy" data-copy="raw">Kopyala</button>
            </div>
            <pre id="raw">{{ json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    @endif

    {{--
        Sağlayıcı ve sipariş numarasını geri taşıyoruz: önizleme sayfası
        sıfırdan açıldığında varsayılan sağlayıcıya düşüyor ve durum
        sorgusu farkında olmadan yanlış sağlayıcıya gidiyordu.
    --}}
    <a class="back" href="{{ route('payment.preview', array_filter([
        'driver' => $driver,
        'order' => $detail['order_id'] ?? null,
        'payment_id' => $detail['payment_id'] ?? null,
    ])) }}">← Yeni ödeme</a>
</main>

<script>
    document.querySelectorAll('button.copy').forEach(function (button) {
        button.addEventListener('click', async function () {
            const source = document.getElementById(button.dataset.copy);

            if (source === null) {
                return;
            }

            const text = source.textContent;

            try {
                // Clipboard API güvenli bağlam ister; localhost buna dâhildir.
                await navigator.clipboard.writeText(text);
            } catch {
                // http:// üzerinden açıldığında API kapalı olur; seçip
                // kopyalama eski yolu her yerde çalışır.
                const area = document.createElement('textarea');
                area.value = text;
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                document.execCommand('copy');
                area.remove();
            }

            const previous = button.textContent;
            button.textContent = 'Kopyalandı';
            button.classList.add('done');

            setTimeout(function () {
                button.textContent = previous;
                button.classList.remove('done');
            }, 1600);
        });
    });
</script>
</body>
</html>
