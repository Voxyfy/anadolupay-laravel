<?php

use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
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
// bekler; önizleme sayfası kimlik doğrulaması istemez.
new #[Layout('layouts.auth')] class extends Component
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
     * Sağlayıcıların yayınladığı test kartları.
     *
     * Tam liste için depodaki TEST-KARTLARI.md dosyasına bakın.
     */
    #[Computed]
    public function cards(): array
    {
        return [
            'iyzico-success' => ['label' => 'iyzico — Başarılı', 'number' => '5890040000000016', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
            'iyzico-insufficient' => ['label' => 'iyzico — Yetersiz bakiye', 'number' => '4111111111111129', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
            'iyzico-not-3ds' => ['label' => 'iyzico — 3D’ye kayıtlı değil', 'number' => '4127763710346799', 'month' => '12', 'year' => '2030', 'cvv' => '123'],
        ];
    }

    /**
     * Seçilen sağlayıcı durum sorgusu destekliyor mu?
     */
    #[Computed]
    public function supportsStatus(): bool
    {
        try {
            return AnadoluPay::driver($this->driver) instanceof SupportsStatusQuery;
        } catch (\Throwable) {
            return false;
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

<div class="mx-auto w-full max-w-2xl space-y-6 py-10">
    <div>
        <flux:heading size="xl">Ödeme Önizleme</flux:heading>
        <flux:subheading>
            AnadoluPay akışını uçtan uca dener: form → banka 3D sayfası → dönüş sonucu.
        </flux:subheading>
    </div>

    @if (session('payment_error'))
        <flux:callout variant="danger" icon="exclamation-triangle">
            <flux:callout.heading>{{ session('payment_error')['title'] }}</flux:callout.heading>
            <flux:callout.text>
                {{ session('payment_error')['message'] }}
                @if (! empty(session('payment_error')['detail']))
                    <pre class="mt-2 overflow-x-auto text-xs">{{ json_encode(session('payment_error')['detail'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                @endif
            </flux:callout.text>
        </flux:callout>
    @endif

    {{--
        Ödeme, bankanın 3D sayfasıyla sonuçlanır. Livewire yanıtı tarayıcıyı
        başka bir belgeye taşıyamayacağı için form klasik POST ile gider.
    --}}
    <form method="POST" action="{{ route('payment.pay') }}" class="space-y-6">
        @csrf
        <input type="hidden" name="order_id" value="{{ $this->orderId }}">

        <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800 space-y-5">
            <flux:legend>Sipariş</flux:legend>

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
                    :description="$this->minorUnits !== null ? $this->minorUnits.' kuruş' : 'geçersiz tutar'"
                />

                <flux:select wire:model="installment" name="installment" label="Taksit">
                    @foreach ([1, 2, 3, 6, 9, 12] as $count)
                        <flux:select.option value="{{ $count }}">
                            {{ $count === 1 ? 'Tek çekim' : $count.' taksit' }}
                        </flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:select wire:model="paymentModel" name="payment_model" label="Ödeme modeli">
                <flux:select.option value="3d">3D Secure — doğrulama sonrası ayrı provizyon</flux:select.option>
                <flux:select.option value="3d_pay">3D Pay — tek adımda</flux:select.option>
                <flux:select.option value="3d_host">3D Host — kart formu bankada</flux:select.option>
                <flux:select.option value="regular">Non-secure — 3D yok</flux:select.option>
            </flux:select>
        </flux:fieldset>

        <flux:fieldset class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800 space-y-5">
            <div class="flex items-center justify-between">
                <flux:legend class="mb-0">Kart</flux:legend>
                <flux:badge size="sm" variant="pill">{{ $this->maskedCard }}</flux:badge>
            </div>

            <flux:select
                wire:model.live="preset"
                label="Hazır test kartı"
                description="Tüm sağlayıcıların kartları için depodaki TEST-KARTLARI.md"
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
        </flux:fieldset>

        <flux:button type="submit" variant="primary" class="w-full">Ödemeyi başlat</flux:button>

        <flux:text size="sm" class="text-zinc-500">
            Sipariş numarası <flux:badge size="sm">{{ $this->orderId }}</flux:badge> —
            3D dönüşü <code>{{ route('payment.callback') }}</code> adresine gelir.
        </flux:text>
    </form>

    <flux:separator />

    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800 space-y-4">
        <div>
            <flux:heading size="sm">Durum sorgusu</flux:heading>
            <flux:subheading>
                Zaman aşımı gibi belirsiz sonuçları kapatmanın tek yolu bankaya sormaktır.
            </flux:subheading>
        </div>

        @if (! $this->supportsStatus)
            <flux:callout variant="warning" icon="information-circle">
                <flux:callout.text>
                    <strong>{{ $driver }}</strong> durum sorgusu sunmuyor. Bu bir eksiklik değil,
                    sağlayıcı sınırıdır — driver <code>SupportsStatusQuery</code> arayüzünü uygulamaz.
                </flux:callout.text>
            </flux:callout>
        @else
            <div class="flex items-end gap-3">
                <flux:input
                    wire:model="statusOrderId"
                    label="Sipariş numarası"
                    placeholder="{{ $this->orderId }}"
                    class="flex-1"
                />
                <flux:button wire:click="checkStatus" wire:loading.attr="disabled" variant="filled">
                    <span wire:loading.remove wire:target="checkStatus">Sorgula</span>
                    <span wire:loading wire:target="checkStatus">Sorgulanıyor…</span>
                </flux:button>
            </div>

            @if ($statusResult)
                @if (isset($statusResult['error']))
                    <flux:callout variant="danger" icon="exclamation-triangle">
                        <flux:callout.text>{{ $statusResult['error'] }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:callout :variant="$statusResult['paid'] ? 'success' : 'secondary'">
                        <flux:callout.text>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                                @foreach ($statusResult as $key => $value)
                                    <dt class="text-zinc-500">{{ $key }}</dt>
                                    <dd class="font-mono">
                                        {{ is_bool($value) ? ($value ? 'true' : 'false') : ($value ?? '—') }}
                                    </dd>
                                @endforeach
                            </dl>
                        </flux:callout.text>
                    </flux:callout>
                @endif
            @endif
        @endif
    </div>
</div>
