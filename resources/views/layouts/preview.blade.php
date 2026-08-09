{{--
    Ödeme önizlemesi için sade, tam genişlikte layout.

    Starter kit'in `auth.simple` layout'u giriş formları için `max-w-sm`
    ile sınırlıdır; önizleme sayfası iki sütun kullandığı için kendi
    kabuğunu kullanır. Oturum da beklemez.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    @include('partials.head')
</head>
<body class="min-h-svh bg-white text-neutral-900 antialiased selection:bg-neutral-900 selection:text-white dark:bg-neutral-950 dark:text-neutral-100 dark:selection:bg-white dark:selection:text-neutral-900">
    {{ $slot }}
    @fluxScripts

    <script>
        /*
         * Panoya kopyalama.
         *
         * `navigator.clipboard` yalnızca güvenli bağlamda (https ya da
         * localhost) tanımlıdır; `http://uygulama.test` gibi bir adreste
         * çağrı sessizce reddedilir. Bu yüzden her koşulda çalışan
         * textarea + execCommand yoluna düşüyoruz ve fonksiyon asla
         * hata fırlatmıyor — çağıran taraf "kopyalandı" göstergesini
         * güvenle açabilsin.
         */
        window.anadoluCopy = async function (text) {
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);

                    return true;
                }
            } catch {
                // Aşağıdaki yedek yola düşülür.
            }

            try {
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.top = '0';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                area.setSelectionRange(0, text.length);
                const ok = document.execCommand('copy');
                area.remove();

                return ok;
            } catch {
                return false;
            }
        };
    </script>
</body>
</html>
