<?php

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Voxyfy\AnadoluPay\Contracts\SupportsBinQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsCancellation;
use Voxyfy\AnadoluPay\Contracts\SupportsInstallmentQuery;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\RefundPaymentData;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\TransportException;
use Voxyfy\AnadoluPay\Facades\AnadoluPay;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Ödeme Önizleme
 *
 * AnadoluPay akışını uçtan uca dener. Ödeme başlatıldığında bankanın 3D
 * sayfası döndüğü için Livewire bileşeni tarayıcıyı oraya taşıyamaz;
 * bunun yerine formu klasik bir POST ile gönderiyoruz. Livewire'ın işi
 * formu hazırlamak, kart ön izlemesi ve durum sorgusu.
 */
// Varsayılan uygulama layout'u kenar çubuğunda oturum açmış kullanıcı
// bekler, `auth.simple` ise formu `max-w-sm` ile sınırlar; önizleme
// sayfası iki sütun kullandığı ve oturum istemediği için kendi kabuğunu
// kullanır.
new #[Layout('layouts.preview')] class extends Component
{
    #[Validate('required|string')]
    public string $driver = 'iyzico';

    #[Validate('required|numeric|min:0.01')]
    public string $amount = '100.00';

    #[Validate('required|integer|min:1|max:12')]
    public int $installment = 1;

    #[Validate('required|string')]
    public string $paymentModel = '3d';

    #[Validate('required|string')]
    public string $cardNumber = '5890040000000016';

    #[Validate('required|string')]
    public string $expireMonth = '12';

    #[Validate('required|string')]
    public string $expireYear = '2030';

    #[Validate('required|string')]
    public string $cvv = '123';

    public string $holderName = 'Test Kullanıcı';

    /** Hazır test kartı seçimi. */
    public string $preset = 'iyzico-success';

    /** Durum sorgusu için sipariş numarası. */
    public string $statusOrderId = '';

    /** Son durum sorgusunun sonucu. */
    public ?array $statusResult = null;

    /** İade/iptal için sağlayıcının verdiği ödeme numarası. */
    public string $opPaymentId = '';

    /** Kısmi iade tutarı; boş bırakılırsa tam iade denenir. */
    public string $opAmount = '';

    /** BIN ve taksit sorgusu için kartın ilk haneleri. */
    public string $opBin = '589004';

    /** Son işlemin sonucu: ['operation' => ..., 'ok' => ..., 'data' => [...]]. */
    public ?array $opResult = null;

    /**
     * Sonuç sayfasından dönüldüğünde sağlayıcıyı ve siparişi geri yükler.
     *
     * Bileşen her açılışta `driver` alanını varsayılana döndürüyordu; bu
     * yüzden ödemeden sonra durum sorgusu farkında olmadan başka bir
     * sağlayıcıya gidip "bulunamadı" diyordu.
     */
    public function mount(Request $request): void
    {
        $driver = (string) $request->query('driver', '');

        if ($driver !== '' && in_array($driver, $this->drivers(), true)) {
            $this->driver = $driver;
        }

        $this->statusOrderId = (string) $request->query('order', '');
        $this->opPaymentId = (string) $request->query('payment_id', '');
    }

    /**
     * Yapılandırılmış tüm sağlayıcılar.
     *
     * @return list<string>
     */
    #[Computed]
    public function drivers(): array
    {
        return AnadoluPay::available();
    }

    /**
     * Sağlayıcıların **resmî** olarak yayınladığı test kartları.
     *
     * Tam liste ve kaynakları için pakette TEST-KARTLARI.md dosyasına bakın.
     * Buraya yalnızca kaynağı doğrulanmış numaralar konur: çalışmayan bir
     * test kartı, hiç kart olmamasından daha çok vakit kaybettirir.
     */
    #[Computed]
    public function cards(): array
    {
        return [
            // iyzico — docs.iyzico.com/en/add-ons/test-cards
            'iyzico-success' => ['label' => 'iyzico — Başarılı (Akbank Master)', 'number' => '5890040000000016', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
            'iyzico-insufficient' => ['label' => 'iyzico — Yetersiz bakiye', 'number' => '4111111111111129', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
            'iyzico-invalid-cvc' => ['label' => 'iyzico — Geçersiz CVC', 'number' => '4124111111111116', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
            'iyzico-3ds-fail' => ['label' => 'iyzico — 3D başlatma başarısız', 'number' => '4151111111111112', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
            'iyzico-mdstatus-0' => ['label' => 'iyzico — Onaylı ama mdStatus=0', 'number' => '4131111111111117', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
            // Garanti — dev.garantibbva.com.tr/test-kartlari (3D OTP: 147852)
            'garanti-simulator' => ['label' => 'Garanti — Simulator (OTP 147852)', 'number' => '4282209004348015', 'month' => '08', 'year' => '2027', 'cvv' => '123'],
            'garanti-bonus' => ['label' => 'Garanti — Bonus (OTP 147852)', 'number' => '5549600732695519', 'month' => '04', 'year' => '2030', 'cvv' => '244'],
            // PayTR — dev.paytr.com
            'paytr-visa' => ['label' => 'PayTR — Visa', 'number' => '4355084355084358', 'month' => '12', 'year' => '2030', 'cvv' => '000'],
            'paytr-master' => ['label' => 'PayTR — Mastercard', 'number' => '5406675406675403', 'month' => '12', 'year' => '2030', 'cvv' => '000'],
            // Craftgate — resmî istemci depolarındaki örneklerden
            'craftgate-master' => ['label' => 'Craftgate — Mastercard', 'number' => '5258640000000001', 'month' => '07', 'year' => '2044', 'cvv' => '000'],
            'craftgate-visa' => ['label' => 'Craftgate — Visa', 'number' => '4256690000000001', 'month' => '11', 'year' => '2035', 'cvv' => '123'],
            // Moka — developer.mokaunited.com/home.php?page=test-kartlari
            // Not: Test bayisinde her bankanın sanal POS'u tanımlı değildir.
            // Garanti kartı `VirtualPosNotAvailable` verir; aşağıdakiler çalışır.
            'moka-isbank' => ['label' => 'Moka — İş Bankası (Visa)', 'number' => '4183441122223339', 'month' => '12', 'year' => '2030', 'cvv' => '000'],
            'moka-akbank' => ['label' => 'Moka — Akbank (Master)', 'number' => '5127541122223332', 'month' => '12', 'year' => '2030', 'cvv' => '000'],
            'moka-ziraat' => ['label' => 'Moka — Ziraat (Master)', 'number' => '5136621122223331', 'month' => '12', 'year' => '2030', 'cvv' => '000'],
            // NestPay (Ziraat ve diğer Asseco bankaları) — 3D SMS şifresi: a
            'nestpay-ziraat-visa' => ['label' => 'Ziraat / NestPay — Visa (3D şifre: a)', 'number' => '4546711234567894', 'month' => '12', 'year' => '2026', 'cvv' => '000'],
            'nestpay-ziraat-master' => ['label' => 'Ziraat / NestPay — Mastercard (3D şifre: a)', 'number' => '5401341234567891', 'month' => '12', 'year' => '2026', 'cvv' => '000'],
            // Paratika — docs.paratika.com.tr/test-kartlari
            'paratika-akbank' => ['label' => 'Paratika — Akbank (Visa)', 'number' => '4355084355084358', 'month' => '12', 'year' => '2030', 'cvv' => '000'],
            'paratika-isbank' => ['label' => 'Paratika — İş Bankası (Visa)', 'number' => '4508034508034509', 'month' => '12', 'year' => '2030', 'cvv' => '000'],
        ];
    }

    /**
     * Seçilen sağlayıcı durum sorgusu destekliyor mu?
     */
    #[Computed]
    public function supportsStatus(): bool
    {
        return $this->capabilities['status'];
    }

    /**
     * Sağlayıcının hangi işlemleri sunduğu.
     *
     * Yetenekler arayüzle bildirilir; desteklenmeyen bir işlemin düğmesini
     * göstermek yerine neden yok olduğunu yazmak daha faydalı.
     *
     * @return array<string, bool>
     */
    #[Computed]
    public function capabilities(): array
    {
        try {
            $gateway = AnadoluPay::driver($this->driver);
        } catch (\Throwable) {
            return ['status' => false, 'cancel' => false, 'bin' => false, 'installment' => false];
        }

        return [
            'status' => $gateway instanceof SupportsStatusQuery,
            'cancel' => $gateway instanceof SupportsCancellation,
            'bin' => $gateway instanceof SupportsBinQuery,
            'installment' => $gateway instanceof SupportsInstallmentQuery,
        ];
    }

    /**
     * Sağlayıcının kimlik bilgileri girilmiş mi?
     *
     * Hangi alanın zorunlu olduğu driver'a göre değişir; burada amaç
     * "hiç doldurulmamış" durumu ayırmak. Eksik yapılandırma hatasını
     * form gönderildikten sonra görmek yerine seçim anında görmek gerekir.
     *
     * @return array<string, mixed>
     */
    #[Computed]
    public function configuration(): array
    {
        if ($this->driver === 'fake') {
            return ['ready' => true, 'label' => 'kimlik bilgisi gerektirmez'];
        }

        $alanlar = $this->driver === 'iyzico'
            ? ['api_key' => config('anadolupay.iyzico.api_key'), 'secret_key' => config('anadolupay.iyzico.secret_key')]
            : array_intersect_key(
                (array) config("anadolupay.banks.{$this->driver}", []),
                array_flip(['merchant_id', 'terminal_id', 'username', 'password', 'secret_key']),
            );

        $dolu = array_filter($alanlar, static fn (mixed $v): bool => is_scalar($v) && (string) $v !== '');

        return [
            'ready' => $dolu !== [],
            'label' => $dolu === []
                ? 'kimlik bilgisi girilmemiş'
                : count($dolu).'/'.count($alanlar).' alan dolu',
        ];
    }

    /**
     * Kimlik bilgisi girilmiş sağlayıcılar.
     *
     * @return list<string>
     */
    #[Computed]
    public function readyDrivers(): array
    {
        $hazir = [];

        foreach ($this->drivers() as $ad) {
            $onceki = $this->driver;
            $this->driver = $ad;

            if ($this->configuration()['ready']) {
                $hazir[] = $ad;
            }

            $this->driver = $onceki;
            unset($this->configuration);
        }

        return $hazir;
    }

    /**
     * İade — tutar verilmezse tam iade denenir.
     */
    public function refund(): void
    {
        $this->runOperation('İade', function ($gateway) {
            $response = $gateway->refund(new RefundPaymentData(
                paymentId: $this->opPaymentId,
                amount: $this->opAmount !== '' ? Money::fromDecimal($this->opAmount) : null,
                metadata: ['conversation_id' => $this->statusOrderId ?: $this->orderId()],
            ));

            return [
                'success' => $response->success,
                'refund_id' => $response->refundId,
                'error' => $response->errorMessage,
                'raw' => $response->raw,
            ];
        });
    }

    /**
     * Gün sonu öncesi iptal.
     */
    public function cancelPayment(): void
    {
        $this->runOperation('İptal', function ($gateway) {
            if (! $gateway instanceof SupportsCancellation) {
                throw new \RuntimeException("'{$this->driver}' iptal sunmuyor.");
            }

            $response = $gateway->cancel(new RefundPaymentData(paymentId: $this->opPaymentId));

            return [
                'success' => $response->success,
                'refund_id' => $response->refundId,
                'error' => $response->errorMessage,
                'raw' => $response->raw,
            ];
        });
    }

    /**
     * BIN sorgusu.
     */
    public function binLookup(): void
    {
        $this->runOperation('BIN sorgusu', function ($gateway) {
            if (! $gateway instanceof SupportsBinQuery) {
                throw new \RuntimeException("'{$this->driver}' BIN sorgusu sunmuyor.");
            }

            $bin = $gateway->binLookup($this->opBin);

            return [
                'found' => $bin->found,
                'bank' => $bin->bankName,
                'brand' => $bin->brand,
                'type' => $bin->type,
                'commercial' => $bin->commercial,
                'raw' => $bin->raw,
            ];
        });
    }

    /**
     * Taksit seçenekleri.
     */
    public function installmentOptions(): void
    {
        $this->runOperation('Taksit sorgusu', function ($gateway) {
            if (! $gateway instanceof SupportsInstallmentQuery) {
                throw new \RuntimeException("'{$this->driver}' taksit sorgusu sunmuyor.");
            }

            $options = $gateway->installmentOptions(
                Money::fromDecimal($this->amount ?: '100.00'),
                $this->opBin !== '' ? $this->opBin : null,
            );

            return [
                'count' => count($options),
                'options' => array_map(fn ($o) => [
                    'taksit' => $o->count,
                    'toplam' => $o->totalPrice?->toDecimalString(),
                    'aylık' => $o->monthlyPrice?->toDecimalString(),
                    'oran' => $o->commissionRate,
                    'banka' => $o->bankName,
                ], $options),
            ];
        });
    }

    /**
     * İşlemleri tek yerde çalıştırır: hata sınıflandırması her birinde aynı.
     *
     * `TransportException` ayrı yakalanır — bankaya ulaşılamadığında işlemin
     * gerçekleşip gerçekleşmediği belirsizdir ve bu ekranda görünmelidir.
     */
    private function runOperation(string $name, callable $callback): void
    {
        $this->opResult = null;

        try {
            $this->opResult = [
                'operation' => $name,
                'ok' => true,
                'data' => $callback(AnadoluPay::driver($this->driver)),
            ];
        } catch (TransportException $e) {
            $this->opResult = [
                'operation' => $name,
                'ok' => false,
                'data' => ['hata' => 'Sağlayıcıya ulaşılamadı — sonuç belirsiz', 'mesaj' => $e->getMessage()],
            ];
        } catch (PaymentFailedException $e) {
            $this->opResult = [
                'operation' => $name,
                'ok' => false,
                'data' => ['hata' => $e->getMessage(), 'bağlam' => $e->context],
            ];
        } catch (\Throwable $e) {
            $this->opResult = [
                'operation' => $name,
                'ok' => false,
                'data' => ['hata' => $e->getMessage(), 'sınıf' => $e::class],
            ];
        }
    }

    /**
     * Kart numarasını maskeli göster — ekranda tam numara durmasın.
     */
    #[Computed]
    public function maskedCard(): string
    {
        try {
            return CardData::fromArray([
                'number' => $this->cardNumber,
                'expire_month' => $this->expireMonth,
                'expire_year' => $this->expireYear,
                'cvv' => $this->cvv,
            ])->masked();
        } catch (\Throwable) {
            return '—';
        }
    }

    /**
     * Tutarın kuruş karşılığı; float yuvarlamasının etkisini görmek için.
     */
    #[Computed]
    public function minorUnits(): ?int
    {
        try {
            return Money::fromDecimal($this->amount)->minorUnits;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Hazır kart seçilince alanları doldurur.
     */
    public function updatedPreset(string $value): void
    {
        $card = $this->cards()[$value] ?? null;

        if ($card === null) {
            return;
        }

        $this->cardNumber = $card['number'];
        $this->expireMonth = $card['month'];
        $this->expireYear = $card['year'];
        $this->cvv = $card['cvv'];

        // BIN alanı seçilen kartı izlesin: elle senkronlamak unutuluyor ve
        // başka bir kartın BIN'iyle yapılan taksit sorgusu yanıltıcı oluyor.
        $this->opBin = substr($card['number'], 0, 6);
    }

    /**
     * Siparişin bankadaki güncel durumunu sorgular.
     *
     * Zaman aşımı gibi belirsiz durumları kapatmanın tek yolu budur.
     */
    public function checkStatus(): void
    {
        $this->statusResult = null;

        if ($this->statusOrderId === '') {
            $this->addError('statusOrderId', 'Sipariş numarası gerekli.');

            return;
        }

        $gateway = AnadoluPay::driver($this->driver);

        if (! $gateway instanceof SupportsStatusQuery) {
            $this->statusResult = ['error' => "'{$this->driver}' durum sorgusu sunmuyor."];

            return;
        }

        try {
            $status = $gateway->status($this->statusOrderId);
        } catch (PaymentFailedException|TransportException $e) {
            $this->statusResult = ['error' => $e->getMessage()];

            return;
        }

        $this->statusResult = [
            'found' => $status->found,
            'status' => $status->status,
            'paid' => $status->isPaid(),
            'payment_id' => $status->paymentId,
            'amount' => $status->amount?->toDecimalString(),
        ];
    }

    /**
     * Formun POST edileceği sipariş numarasını üretir.
     *
     * Her render'da yeni bir numara vermek yerine bileşen ömrü boyunca
     * sabit kalır; kullanıcı durum sorgusunda aynı numarayı kullanabilir.
     */
    #[Computed(persist: true)]
    public function orderId(): string
    {
        return 'TEST-'.strtoupper(Str::random(10));
    }
}; ?>
<div class="min-h-svh">
    {{-- Üst şerit: marka ve sipariş referansı --}}
    <header class="sticky top-0 z-30 border-b border-neutral-200/70 bg-white/80 backdrop-blur-xl dark:border-white/10 dark:bg-neutral-950/80">
        <div class="mx-auto flex h-16 w-full max-w-6xl items-center justify-between px-6">
            <a href="{{ route('home') }}" class="flex items-center gap-3">
                {{--
                    Marka görseli 1280×640 bir kart; şeride kırpılmadan
                    sığmıyor. Üst şeritte starter kit'in kendi işareti
                    kullanılıyor, tam görsel README ve sosyal önizlemede.
                --}}
                <x-app-logo-icon class="size-7 fill-current text-neutral-900 dark:text-white" />
                <span class="flex items-baseline gap-2">
                    <span class="text-sm font-semibold tracking-tight">AnadoluPay</span>
                    <span class="hidden text-sm text-neutral-400 sm:inline">Ödeme Önizleme</span>
                </span>
            </a>

            <div class="flex items-center gap-2 font-mono text-[11px] text-neutral-500 dark:text-neutral-400">
                <span class="hidden sm:inline">sipariş</span>
                <span class="rounded-md border border-neutral-200 px-2 py-1 dark:border-white/10">{{ $this->orderId }}</span>
            </div>
        </div>
    </header>

    <main class="mx-auto w-full max-w-6xl px-6 pb-24">
        {{-- Hero --}}
        <div class="max-w-2xl py-14 sm:py-20">
            <h1 class="text-4xl font-semibold tracking-tight text-balance sm:text-5xl">
                Gerçek bankaya, gerçek istek.
            </h1>
            <p class="mt-4 text-lg leading-relaxed text-neutral-500 dark:text-neutral-400">
                Form → sağlayıcının 3D sayfası → dönüş doğrulaması. Akışın tamamı
                paketin kendi driver'ları üzerinden çalışır; hiçbir adım taklit edilmez.
            </p>
        </div>

        @if (session('payment_error'))
            <div class="mb-10 rounded-2xl border border-red-200 bg-red-50 p-6 dark:border-red-500/25 dark:bg-red-500/10">
                <div class="text-sm font-semibold text-red-900 dark:text-red-200">
                    {{ session('payment_error')['title'] }}
                </div>
                <p class="mt-1 text-sm text-red-800/90 dark:text-red-200/80">
                    {{ session('payment_error')['message'] }}
                </p>
                @if (! empty(session('payment_error')['detail']))
                    <pre class="mt-4 overflow-x-auto rounded-xl bg-red-900/5 p-4 font-mono text-[11px] leading-relaxed text-red-900 dark:bg-black/30 dark:text-red-200">{{ json_encode(session('payment_error')['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                @endif
            </div>
        @endif

        {{--
            Ödeme, sağlayıcının 3D sayfasıyla sonuçlanır. Livewire yanıtı
            tarayıcıyı başka bir belgeye taşıyamayacağı için form klasik
            POST ile gider; Livewire yalnızca formu hazırlar.

            Form iki sütunu birden sarar: alanları `form` özniteliğiyle
            dışarıdan bağlamak, Flux bileşenlerinin bu özniteliği iç
            `<input>`'a geçirmesine bel bağlamak olurdu.
        --}}
        {{--
            Eksik yapılandırmayı formu gönderdikten sonra değil, sağlayıcıyı
            seçer seçmez göster.
        --}}
        @unless ($this->configuration['ready'])
            <div class="mb-8 rounded-2xl border border-amber-200 bg-amber-50 p-6 dark:border-amber-500/25 dark:bg-amber-500/10">
                <div class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                    <strong>{{ $driver }}</strong> için kimlik bilgisi girilmemiş
                </div>
                <p class="mt-1 text-sm text-amber-800/90 dark:text-amber-200/80">
                    Bu sağlayıcıyla ödeme denenemez. Anahtarları <code class="font-mono text-[12px]">.env</code>
                    dosyasına ekleyip <code class="font-mono text-[12px]">php artisan config:clear</code> çalıştırın.
                </p>
                @if ($this->readyDrivers !== [])
                    <p class="mt-3 text-sm text-amber-800/90 dark:text-amber-200/80">
                        Şu an hazır olanlar:
                        @foreach ($this->readyDrivers as $hazir)
                            <button type="button" wire:click="$set('driver', '{{ $hazir }}')"
                                    class="mr-1 rounded-md border border-amber-300 px-2 py-0.5 font-mono text-[12px] hover:bg-amber-100 dark:border-amber-500/30 dark:hover:bg-amber-500/10">{{ $hazir }}</button>
                        @endforeach
                    </p>
                @endif
            </div>
        @endunless

        <form method="POST" action="{{ route('payment.pay') }}"
              class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_380px] lg:items-start">
            @csrf
            <input type="hidden" name="order_id" value="{{ $this->orderId }}">
            {{-- Sol sütun: girdiler --}}
            <div class="space-y-8">
                <section class="rounded-3xl border border-neutral-200/80 bg-white p-6 sm:p-8 dark:border-white/10 dark:bg-white/[0.02]">
                    <div class="mb-6 flex items-baseline justify-between">
                        <h2 class="text-[11px] font-semibold tracking-[0.16em] text-neutral-400 uppercase">Sipariş</h2>
                        <span class="font-mono text-[11px] text-neutral-400">
                            {{ $this->minorUnits !== null ? number_format($this->minorUnits).' kuruş' : 'geçersiz tutar' }}
                        </span>
                    </div>

                    <div class="space-y-5">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:select wire:model.live="driver" name="driver" label="Sağlayıcı">
                                @foreach ($this->drivers as $option)
                                    <flux:select.option value="{{ $option }}">{{ $option }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input
                                wire:model.live.debounce.400ms="amount"
                                name="amount"
                               
                                label="Tutar (TL)"
                                type="number"
                                step="0.01"
                                min="0.01"
                            />

                            <flux:select wire:model="installment" name="installment" label="Taksit">
                                @foreach ([1, 2, 3, 6, 9, 12] as $count)
                                    <flux:select.option value="{{ $count }}">
                                        {{ $count === 1 ? 'Tek çekim' : $count.' taksit' }}
                                    </flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>

                        <flux:select wire:model.live="paymentModel" name="payment_model" label="Ödeme modeli">
                            <flux:select.option value="3d">3D Secure — doğrulama sonrası ayrı provizyon</flux:select.option>
                            <flux:select.option value="3d_pay">3D Pay — tek adımda</flux:select.option>
                            <flux:select.option value="3d_host">3D Host — kart formu sağlayıcıda</flux:select.option>
                            <flux:select.option value="regular">Non-secure — 3D yok</flux:select.option>
                        </flux:select>
                    </div>
                </section>

                <section class="rounded-3xl border border-neutral-200/80 bg-white p-6 sm:p-8 dark:border-white/10 dark:bg-white/[0.02]">
                    <div class="mb-6 flex items-baseline justify-between">
                        <h2 class="text-[11px] font-semibold tracking-[0.16em] text-neutral-400 uppercase">Kart</h2>
                        <span class="font-mono text-[11px] text-neutral-400">{{ $this->maskedCard }}</span>
                    </div>

                    <div class="space-y-5">
                        <flux:select
                            wire:model.live="preset"
                            label="Hazır test kartı"
                            description="Yalnızca resmî kaynaktan doğrulanmış numaralar. Tam liste: TEST-KARTLARI.md"
                        >
                            <flux:select.option value="">— elle gir —</flux:select.option>
                            @foreach ($this->cards as $key => $card)
                                <flux:select.option value="{{ $key }}">{{ $card['label'] }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input wire:model.live="cardNumber" name="card_number" label="Kart numarası" inputmode="numeric" />

                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:input wire:model="expireMonth" name="expire_month" label="Ay" maxlength="2" inputmode="numeric" />
                            <flux:input wire:model="expireYear" name="expire_year" label="Yıl" maxlength="4" inputmode="numeric" />
                            <flux:input wire:model="cvv" name="cvv" label="CVV" maxlength="4" inputmode="numeric" />
                        </div>

                        <flux:input wire:model="holderName" name="holder_name" label="Kart sahibi" />
                    </div>
                </section>
            </div>

            {{-- Sağ sütun: özet ve eylem --}}
            <aside class="lg:sticky lg:top-24">
                <div class="overflow-hidden rounded-3xl border border-neutral-200/80 bg-white dark:border-white/10 dark:bg-white/[0.02]">
                    <div class="border-b border-neutral-200/70 p-6 sm:p-8 dark:border-white/10">
                        <div class="text-[11px] font-semibold tracking-[0.16em] text-neutral-400 uppercase">Tahsil edilecek</div>
                        <div class="mt-3 flex items-baseline gap-2">
                            <span class="text-5xl font-semibold tracking-tight tabular-nums">
                                {{ number_format((float) ($this->amount ?: 0), 2, ',', '.') }}
                            </span>
                            <span class="text-lg text-neutral-400">TL</span>
                        </div>
                        @if ($installment > 1)
                            <div class="mt-2 text-sm text-neutral-500 dark:text-neutral-400">
                                {{ $installment }} taksit ·
                                {{ number_format(((float) ($this->amount ?: 0)) / $installment, 2, ',', '.') }} TL/ay
                            </div>
                        @endif
                    </div>

                    <dl class="divide-y divide-neutral-200/70 text-sm dark:divide-white/5">
                        @foreach ([
                            'Sağlayıcı' => $driver,
                            'Model' => $paymentModel,
                            'Kart' => $this->maskedCard,
                            'Kuruş' => $this->minorUnits !== null ? number_format($this->minorUnits) : '—',
                        ] as $label => $value)
                            <div class="flex items-center justify-between gap-6 px-6 py-3 sm:px-8">
                                <dt class="text-neutral-500 dark:text-neutral-400">{{ $label }}</dt>
                                <dd class="font-mono text-[12px]">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>

                    <div class="p-6 sm:p-8">
                        <button
                            type="submit"
                           
                            class="w-full rounded-2xl bg-neutral-900 px-6 py-4 text-[15px] font-medium text-white transition hover:bg-neutral-700 focus-visible:ring-2 focus-visible:ring-neutral-900 focus-visible:ring-offset-2 focus-visible:outline-none dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200 dark:focus-visible:ring-white dark:focus-visible:ring-offset-neutral-950"
                        >
                            Ödemeyi başlat
                        </button>

                        <p class="mt-4 text-[12px] leading-relaxed text-neutral-400">
                            3D dönüşü <code class="font-mono">{{ route('payment.callback') }}</code>
                            adresine gelir ve imzası paket tarafından doğrulanır.
                        </p>
                    </div>
                </div>
            </aside>
        </form>

        {{--
            Durum sorgusu form'un dışında: içinde kalsaydı Livewire
            düğmesi ödeme formunu göndermeye çalışırdı.
        --}}
        <section class="mt-8 rounded-3xl border border-neutral-200/80 bg-white p-6 sm:p-8 dark:border-white/10 dark:bg-white/[0.02]">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-[11px] font-semibold tracking-[0.16em] text-neutral-400 uppercase">Durum sorgusu</h2>
                <span class="rounded-full border border-neutral-200 px-2.5 py-1 font-mono text-[11px] text-neutral-500 dark:border-white/10 dark:text-neutral-400">
                    {{ $driver }}
                </span>
            </div>
            <p class="mt-2 max-w-xl text-sm leading-relaxed text-neutral-500 dark:text-neutral-400">
                Zaman aşımı gibi belirsiz sonuçları kapatmanın tek yolu sağlayıcıya sormaktır.
                Sorgu <strong class="font-semibold text-neutral-700 dark:text-neutral-200">{{ $driver }}</strong>
                driver'ına gider; sipariş numarası da o sağlayıcıdaki numaradır.
            </p>

            @if (! $this->supportsStatus)
                <div class="mt-5 rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/25 dark:bg-amber-500/10 dark:text-amber-200">
                    <strong class="font-semibold">{{ $driver }}</strong> durum sorgusu sunmuyor. Bu bir
                    eksiklik değil, sağlayıcı sınırıdır — driver
                    <code class="font-mono text-[12px]">SupportsStatusQuery</code> arayüzünü uygulamaz.
                </div>
            @else
                <div class="mt-5 flex max-w-xl items-end gap-3">
                    <flux:input wire:model="statusOrderId" label="Sipariş numarası" placeholder="{{ $this->orderId }}" class="flex-1" />
                    <flux:button type="button" wire:click="checkStatus" wire:loading.attr="disabled" variant="filled">
                        <span wire:loading.remove wire:target="checkStatus">Sorgula</span>
                        <span wire:loading wire:target="checkStatus">Sorgulanıyor…</span>
                    </flux:button>
                </div>

                @if ($statusResult)
                    @if (isset($statusResult['error']))
                        <div class="mt-5 max-w-xl rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-500/25 dark:bg-red-500/10 dark:text-red-200">
                            {{ $statusResult['error'] }}
                        </div>
                    @else
                        {{--
                            Sonucu paylaşmak gerektiğinde alanları tek tek
                            seçmek yerine JSON'u olduğu gibi kopyalatıyoruz.

                            JSON, Alpine bileşeninin kendi verisinde durur:
                            `<script type="application/json">` içine koymak
                            daha temiz görünse de Livewire DOM'u yeniden
                            yazarken script etiketlerini koruyamıyor ve
                            `$refs` boş kalıyordu.
                        --}}
                        <div class="mt-5 max-w-xl" x-data="{ kopyalandi: false, json: @js(json_encode($statusResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }">

                            <div class="mb-2 flex items-center justify-between gap-4">
                                <span class="text-[11px] font-semibold tracking-[0.16em] text-neutral-400 uppercase">Sonuç</span>
                                <button
                                    type="button"
                                    x-on:click="
                                        anadoluCopy(json);
                                        kopyalandi = true;
                                        setTimeout(() => kopyalandi = false, 1600)
                                    "
                                    class="rounded-lg border px-2.5 py-1.5 text-[12px] font-medium transition"
                                    :class="kopyalandi
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : 'border-neutral-200 text-neutral-500 hover:border-neutral-400 hover:text-neutral-900 dark:border-white/10 dark:text-neutral-400 dark:hover:border-white/25 dark:hover:text-white'"
                                    x-text="kopyalandi ? 'Kopyalandı' : 'JSON kopyala'"
                                ></button>
                            </div>

                            <dl class="divide-y divide-neutral-200/70 overflow-hidden rounded-2xl border border-neutral-200/70 text-sm dark:divide-white/5 dark:border-white/10">
                                @foreach ($statusResult as $key => $value)
                                    <div class="flex items-center justify-between gap-6 px-4 py-2.5">
                                        <dt class="text-neutral-500 dark:text-neutral-400">{{ $key }}</dt>
                                        <dd class="font-mono text-[12px]">
                                            {{ is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—') }}
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>
                    @endif
                @endif
            @endif
        </section>

        {{--
            Ödeme sonrası işlemler. Hepsi yetenek arayüzüyle koşullu:
            sağlayıcı sunmuyorsa düğme yerine sebebi görünür.
        --}}
        <section class="mt-8 rounded-3xl border border-neutral-200/80 bg-white p-6 sm:p-8 dark:border-white/10 dark:bg-white/[0.02]">
            <div class="flex items-baseline justify-between gap-4">
                <h2 class="text-[11px] font-semibold tracking-[0.16em] text-neutral-400 uppercase">Ödeme sonrası işlemler</h2>
                {{-- İşlemler yukarıda seçili sağlayıcıya gider; hangisi olduğu görünsün. --}}
                <span class="rounded-full border border-neutral-200 px-2.5 py-1 font-mono text-[11px] text-neutral-500 dark:border-white/10 dark:text-neutral-400">
                    {{ $driver }}
                </span>
            </div>
            <p class="mt-2 max-w-xl text-sm leading-relaxed text-neutral-500 dark:text-neutral-400">
                İade, iptal, BIN ve taksit sorgusu — <strong class="font-semibold text-neutral-700 dark:text-neutral-200">{{ $driver }}</strong>
                üzerinden çalışır. Ödeme numarası sağlayıcının döndürdüğü değerdir;
                sonuç sayfasındaki <code class="font-mono text-[12px]">payment_id</code>.
            </p>

            <div class="mt-6 grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="opPaymentId" label="Ödeme numarası" placeholder="37192321" />
                <flux:input wire:model="opAmount" label="İade tutarı" placeholder="boş = tam iade" />
                {{--
                    Açıklamayı `description` ile vermek alanın altında fazladan
                    bir satır açıp üç sütunlu ızgarayı bozuyordu; etiketin
                    yanına yaslandı.
                --}}
                <flux:field>
                    <div class="flex items-baseline justify-between gap-2">
                        <flux:label>BIN</flux:label>
                        <span class="text-[11px] whitespace-nowrap text-neutral-400">karttan alınır</span>
                    </div>
                    <flux:input wire:model="opBin" placeholder="589004" />
                </flux:field>
            </div>

            <div class="mt-5 flex flex-wrap gap-2">
                <flux:button type="button" wire:click="refund" wire:loading.attr="disabled" variant="filled">
                    <span wire:loading.remove wire:target="refund">İade et</span>
                    <span wire:loading wire:target="refund">İade ediliyor…</span>
                </flux:button>

                @if ($this->capabilities['cancel'])
                    <flux:button type="button" wire:click="cancelPayment" wire:loading.attr="disabled" variant="filled">
                        <span wire:loading.remove wire:target="cancelPayment">İptal et</span>
                        <span wire:loading wire:target="cancelPayment">İptal ediliyor…</span>
                    </flux:button>
                @endif

                @if ($this->capabilities['bin'])
                    <flux:button type="button" wire:click="binLookup" wire:loading.attr="disabled" variant="ghost">
                        <span wire:loading.remove wire:target="binLookup">BIN sorgula</span>
                        <span wire:loading wire:target="binLookup">Sorgulanıyor…</span>
                    </flux:button>
                @endif

                @if ($this->capabilities['installment'])
                    <flux:button type="button" wire:click="installmentOptions" wire:loading.attr="disabled" variant="ghost">
                        <span wire:loading.remove wire:target="installmentOptions">Taksitleri sorgula</span>
                        <span wire:loading wire:target="installmentOptions">Sorgulanıyor…</span>
                    </flux:button>
                @endif
            </div>

            @php
                $eksik = collect([
                    'cancel' => 'iptal',
                    'bin' => 'BIN sorgusu',
                    'installment' => 'taksit sorgusu',
                ])->reject(fn ($ad, $anahtar) => $this->capabilities[$anahtar])->values();
            @endphp

            @if ($eksik->isNotEmpty())
                <p class="mt-4 text-[12px] text-neutral-400">
                    <strong class="font-semibold">{{ $driver }}</strong> şunları sunmuyor:
                    {{ $eksik->join(', ', ' ve ') }}. Bu bir eksiklik değil, sağlayıcı sınırıdır —
                    driver ilgili arayüzü uygulamaz.
                </p>
            @endif

            @if ($opResult)
                <div class="mt-6" x-data="{ kopyalandi: false, json: @js(json_encode($opResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) }">

                    <div class="mb-2 flex items-center justify-between gap-4">
                        <span class="flex items-center gap-2 text-[11px] font-semibold tracking-[0.16em] uppercase {{ $opResult['ok'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $opResult['operation'] }} · {{ $opResult['ok'] ? 'tamam' : 'hata' }}
                        </span>
                        <button
                            type="button"
                            x-on:click="
                                anadoluCopy(json);
                                kopyalandi = true;
                                setTimeout(() => kopyalandi = false, 1600)
                            "
                            class="rounded-lg border px-2.5 py-1.5 text-[12px] font-medium transition"
                            :class="kopyalandi
                                ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                : 'border-neutral-200 text-neutral-500 hover:border-neutral-400 hover:text-neutral-900 dark:border-white/10 dark:text-neutral-400 dark:hover:border-white/25 dark:hover:text-white'"
                            x-text="kopyalandi ? 'Kopyalandı' : 'JSON kopyala'"
                        ></button>
                    </div>

                    <pre class="overflow-x-auto rounded-2xl border border-neutral-200/70 bg-neutral-50 p-4 font-mono text-[11.5px] leading-relaxed whitespace-pre-wrap dark:border-white/10 dark:bg-black/30">{{ json_encode($opResult['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif
        </section>
    </main>
</div>
