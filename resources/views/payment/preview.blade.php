<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AnadoluPay — Ödeme Önizleme</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body { font: 15px/1.55 ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif;
               margin: 0; padding: 2.5rem 1.25rem; background: #f6f7f9; color: #17181c; }
        @media (prefers-color-scheme: dark) { body { background: #14151a; color: #e7e8ea; } }
        .wrap { max-width: 680px; margin: 0 auto; }
        h1 { font-size: 1.35rem; margin: 0 0 .35rem; letter-spacing: -.01em; }
        .sub { color: #6b7280; margin: 0 0 1.75rem; font-size: .9rem; }
        .card { background: #fff; border: 1px solid #e4e6ea; border-radius: 12px; padding: 1.5rem; }
        @media (prefers-color-scheme: dark) { .card { background: #1c1e24; border-color: #2b2e36; } }
        label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .35rem; color: #4b5563; }
        @media (prefers-color-scheme: dark) { label { color: #9ca3af; } }
        input, select { width: 100%; padding: .6rem .7rem; border: 1px solid #d5d8dd; border-radius: 8px;
                        font: inherit; background: #fff; color: inherit; }
        @media (prefers-color-scheme: dark) { input, select { background: #23262e; border-color: #343842; } }
        .row { display: grid; gap: .9rem; margin-bottom: .9rem; }
        .row-2 { grid-template-columns: 1fr 1fr; }
        .row-3 { grid-template-columns: 1fr 1fr 1fr; }
        button { width: 100%; padding: .75rem; border: 0; border-radius: 8px; background: #1f6feb;
                 color: #fff; font: 600 15px/1 inherit; cursor: pointer; margin-top: .6rem; }
        button:hover { background: #1a5fd0; }
        fieldset { border: 0; padding: 0; margin: 0 0 1.4rem; }
        legend { font-size: .72rem; font-weight: 700; text-transform: uppercase;
                 letter-spacing: .06em; color: #9099a6; padding: 0; margin-bottom: .8rem; }
        .alert { border-radius: 10px; padding: .9rem 1rem; margin-bottom: 1.25rem; font-size: .88rem; }
        .alert-error { background: #fdeaea; border: 1px solid #f3c2c2; color: #8a1c1c; }
        @media (prefers-color-scheme: dark) { .alert-error { background: #2c1717; border-color: #5a2626; color: #f3b5b5; } }
        .alert pre { margin: .6rem 0 0; font-size: .76rem; overflow-x: auto; white-space: pre-wrap; word-break: break-word; }
        .hint { font-size: .78rem; color: #6b7280; margin-top: .4rem; }
        .note { font-size: .8rem; color: #6b7280; border-top: 1px solid #e4e6ea; margin-top: 1.4rem; padding-top: 1rem; }
        @media (prefers-color-scheme: dark) { .note { border-color: #2b2e36; } }
        code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85em; }
    </style>
</head>
<body>
<div class="wrap">
    <h1>Ödeme Önizleme</h1>
    <p class="sub">AnadoluPay akışını uçtan uca dener: form → banka 3D sayfası → dönüş sonucu.</p>

    @if ($lastResult)
        <div class="alert alert-error">
            <strong>{{ $lastResult['title'] }}</strong><br>
            {{ $lastResult['message'] }}
            @if (! empty($lastResult['detail']))
                <pre>{{ json_encode($lastResult['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @endif
        </div>
    @endif

    <form method="POST" action="{{ route('payment.pay') }}" class="card">
        @csrf

        <fieldset>
            <legend>Sipariş</legend>
            <div class="row row-3">
                <div>
                    <label for="driver">Sağlayıcı</label>
                    <select name="driver" id="driver">
                        @foreach ($drivers as $driver)
                            <option value="{{ $driver }}" @selected($driver === 'iyzico')>{{ $driver }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="amount">Tutar (TL)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="amount" value="100.00">
                </div>
                <div>
                    <label for="installment">Taksit</label>
                    <select name="installment" id="installment">
                        @foreach ([1, 2, 3, 6, 9, 12] as $count)
                            <option value="{{ $count }}">{{ $count === 1 ? 'Tek çekim' : $count.' taksit' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div>
                <label for="payment_model">Ödeme modeli</label>
                <select name="payment_model" id="payment_model">
                    <option value="3d">3D Secure — doğrulama sonrası ayrı provizyon</option>
                    <option value="3d_pay">3D Pay — tek adımda</option>
                    <option value="3d_host">3D Host — kart formu bankada</option>
                    <option value="regular">Non-secure — 3D yok</option>
                </select>
            </div>
        </fieldset>

        <fieldset>
            <legend>Kart</legend>
            <div style="margin-bottom:.9rem">
                <label for="preset">Hazır test kartı</label>
                <select id="preset">
                    <option value="">— elle gir —</option>
                    @foreach ($cards as $key => $card)
                        <option value="{{ $key }}"
                                data-number="{{ $card['number'] }}"
                                data-month="{{ $card['month'] }}"
                                data-year="{{ $card['year'] }}"
                                data-cvv="{{ $card['cvv'] }}">{{ $card['label'] }}</option>
                    @endforeach
                </select>
                <p class="hint">Tüm sağlayıcıların test kartları için depodaki <code>TEST-KARTLARI.md</code>.</p>
            </div>

            <div class="row">
                <div>
                    <label for="card_number">Kart numarası</label>
                    <input name="card_number" id="card_number" value="5890040000000016" inputmode="numeric">
                </div>
            </div>
            <div class="row row-3">
                <div>
                    <label for="expire_month">Ay</label>
                    <input name="expire_month" id="expire_month" value="12" maxlength="2" inputmode="numeric">
                </div>
                <div>
                    <label for="expire_year">Yıl</label>
                    <input name="expire_year" id="expire_year" value="2030" maxlength="4" inputmode="numeric">
                </div>
                <div>
                    <label for="cvv">CVV</label>
                    <input name="cvv" id="cvv" value="123" maxlength="4" inputmode="numeric">
                </div>
            </div>
            <div>
                <label for="holder_name">Kart sahibi</label>
                <input name="holder_name" id="holder_name" value="Test Kullanıcı">
            </div>
        </fieldset>

        <button type="submit">Ödemeyi başlat</button>

        <p class="note">
            3D dönüşü <code>{{ route('payment.callback') }}</code> adresine gelir.
            Banka bu adrese <strong>internetten</strong> ulaşabilmelidir; yerel geliştirmede
            ngrok gibi bir tünel gerekir.
        </p>
    </form>
</div>

<script>
    document.getElementById('preset').addEventListener('change', function () {
        const option = this.selectedOptions[0];
        if (!option.value) return;
        document.getElementById('card_number').value = option.dataset.number;
        document.getElementById('expire_month').value = option.dataset.month;
        document.getElementById('expire_year').value = option.dataset.year;
        document.getElementById('cvv').value = option.dataset.cvv;
    });
</script>
</body>
</html>
