<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use Voxyfy\AnadoluPay\Contracts\SupportsStatusQuery;
use Voxyfy\AnadoluPay\DTO\CardData;
use Voxyfy\AnadoluPay\DTO\CreatePaymentData;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;
use Voxyfy\AnadoluPay\Exceptions\TransportException;
use Voxyfy\AnadoluPay\Facades\AnadoluPay;
use Voxyfy\AnadoluPay\Support\Money;

/**
 * Ödeme Önizleme
 *
 * AnadoluPay akışını uçtan uca denemek için basit bir arayüz:
 * form → banka 3D sayfası → dönüş sonucu.
 */
class PaymentTestController extends Controller
{
    /**
     * Önizleme formu.
     */
    public function index()
    {
        return view('payment.preview', [
            'drivers' => AnadoluPay::available(),
            'cards' => $this->testCards(),
            'lastResult' => session('payment_result'),
        ]);
    }

    /**
     * Ödemeyi başlatır ve müşteriyi bankaya yönlendirir.
     */
    public function pay(Request $request)
    {
        $validated = $request->validate([
            'driver' => ['required', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'installment' => ['required', 'integer', 'min:1', 'max:12'],
            'payment_model' => ['required', 'string'],
            'card_number' => ['required', 'string'],
            'expire_month' => ['required', 'string'],
            'expire_year' => ['required', 'string'],
            'cvv' => ['required', 'string'],
            'holder_name' => ['nullable', 'string'],
        ]);

        $orderId = 'TEST-'.strtoupper(Str::random(10));

        $data = new CreatePaymentData(
            // Tutarı kuruş cinsinden taşımak float yuvarlama hatalarını önler.
            amount: Money::fromDecimal($validated['amount']),
            currency: 'TRY',
            orderId: $orderId,
            customer: [
                'id' => $orderId,
                'name' => 'Test Kullanıcı',
                'email' => 'test@example.com',
                'phone' => '5350000000',
                'gsmNumber' => '+905350000000',
                'identityNumber' => '74300864791',
                'address' => 'Örnek Mah. Test Sok. No:1',
                'city' => 'Istanbul',
                'country' => 'Turkey',
                'zipCode' => '34000',
            ],
            successUrl: route('payment.callback'),
            failUrl: route('payment.callback'),
            card: new CardData(
                number: $validated['card_number'],
                expireMonth: $validated['expire_month'],
                expireYear: $validated['expire_year'],
                cvv: $validated['cvv'],
                holderName: $validated['holder_name'] ?: 'Test Kullanıcı',
            ),
            installment: (int) $validated['installment'],
            paymentModel: $validated['payment_model'],
            ip: $request->ip(),
        );

        // Dönüşte hangi siparişi doğruladığımızı bilmek için saklıyoruz.
        session([
            'payment_context' => [
                'driver' => $validated['driver'],
                'order_id' => $orderId,
                'amount' => $validated['amount'],
                'installment' => (int) $validated['installment'],
            ],
        ]);

        try {
            $response = AnadoluPay::driver($validated['driver'])->createPayment($data);
        } catch (PaymentFailedException $e) {
            return $this->back('Banka isteği reddetti', $e->getMessage(), $e->context);
        } catch (TransportException $e) {
            // Belirsiz sonuç: işlem bankaya ulaşmış olabilir.
            return $this->back('Bankaya ulaşılamadı', $e->getMessage(), $e->context + [
                'safe_to_retry' => $e->safeToRetry,
            ]);
        } catch (Throwable $e) {
            return $this->back('Beklenmeyen hata', $e->getMessage(), ['class' => $e::class]);
        }

        // Sadece yönlendirme URL'i döndüyse (Tosla 3D Host gibi) oraya git.
        if (! $response->requiresForm() && $response->redirectUrl !== null) {
            return redirect()->away($response->redirectUrl);
        }

        if (! $response->requiresForm()) {
            return $this->back(
                'Banka 3D içeriği döndürmedi',
                'Yanıtta ne form alanı ne de HTML var.',
                $response->raw,
            );
        }

        // toHtmlForm() hem form alanlarını hem hazır HTML sayfasını ele alır.
        return response($response->toHtmlForm());
    }

    /**
     * Bankanın 3D dönüşünü doğrular ve sonucu gösterir.
     */
    public function callback(Request $request)
    {
        $context = session('payment_context', []);
        $driver = $context['driver'] ?? 'iyzico';

        $payload = $request->all();

        try {
            $result = AnadoluPay::driver($driver)->verify(new VerifyPaymentData(
                payload: $payload,
                headers: $request->headers->all(),
                rawBody: $request->getContent(),
                order: [
                    'id' => $context['order_id'] ?? null,
                    'amount' => $context['amount'] ?? null,
                    'currency' => 'TRY',
                    'installment' => $context['installment'] ?? 1,
                    'ip' => $request->ip(),
                ],
            ));
        } catch (InvalidSignatureException $e) {
            return $this->result($driver, 'İmza doğrulanamadı', false, $payload, $e->context);
        } catch (TransportException $e) {
            return $this->result($driver, 'Bankaya ulaşılamadı — durum belirsiz', false, $payload, $e->context);
        } catch (Throwable $e) {
            return $this->result($driver, $e->getMessage(), false, $payload, ['class' => $e::class]);
        }

        return $this->result(
            driver: $driver,
            message: $result->success ? 'Ödeme başarılı' : 'Ödeme alınamadı',
            success: $result->success,
            payload: $payload,
            detail: [
                'payment_id' => $result->paymentId,
                'status' => $result->status,
                'order_id' => $context['order_id'] ?? null,
            ],
            raw: $result->raw,
        );
    }

    /**
     * Siparişin bankadaki güncel durumunu sorgular.
     *
     * Zaman aşımı gibi belirsiz durumları kapatmanın tek yolu budur.
     */
    public function status(Request $request)
    {
        $driver = (string) $request->query('driver', 'iyzico');
        $orderId = (string) $request->query('order_id', '');

        $gateway = AnadoluPay::driver($driver);

        if (! $gateway instanceof SupportsStatusQuery) {
            return back()->with('payment_result', [
                'success' => false,
                'title' => 'Desteklenmiyor',
                'message' => "'{$driver}' driver'ı durum sorgusu sunmuyor.",
            ]);
        }

        try {
            $status = $gateway->status($orderId);
        } catch (Throwable $e) {
            return $this->result($driver, $e->getMessage(), false, [], ['class' => $e::class]);
        }

        return $this->result(
            driver: $driver,
            message: $status->found ? "Durum: {$status->status}" : 'Banka bu siparişi tanımıyor',
            success: $status->isPaid(),
            payload: [],
            detail: [
                'found' => $status->found,
                'status' => $status->status,
                'payment_id' => $status->paymentId,
                'amount' => $status->amount?->toDecimalString(),
            ],
            raw: $status->raw,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function back(string $title, string $message, array $context = [])
    {
        Log::warning('AnadoluPay önizleme hatası', ['title' => $title, 'message' => $message]);

        return redirect()->route('payment.preview')->with('payment_result', [
            'success' => false,
            'title' => $title,
            'message' => $message,
            'detail' => $context,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $detail
     * @param  array<string, mixed>  $raw
     */
    protected function result(
        string $driver,
        string $message,
        bool $success,
        array $payload = [],
        array $detail = [],
        array $raw = [],
    ) {
        return view('payment.result', [
            'driver' => $driver,
            'message' => $message,
            'success' => $success,
            'payload' => $payload,
            'detail' => $detail,
            'raw' => $raw,
        ]);
    }

    /**
     * Sağlayıcıların yayınladığı test kartları.
     *
     * Tam liste için depodaki TEST-KARTLARI.md dosyasına bakın.
     *
     * @return array<string, array{label: string, number: string, month: string, year: string, cvv: string}>
     */
    protected function testCards(): array
    {
        return [
            'iyzico-success' => [
                'label' => 'iyzico — Başarılı (Halkbank Master)',
                'number' => '5890040000000016',
                'month' => '12',
                'year' => '2030',
                'cvv' => '123',
            ],
            'iyzico-insufficient' => [
                'label' => 'iyzico — Yetersiz bakiye',
                'number' => '4111111111111129',
                'month' => '12',
                'year' => '2030',
                'cvv' => '123',
            ],
            'iyzico-not-3ds' => [
                'label' => 'iyzico — 3D Secure’a kayıtlı değil',
                'number' => '4127763710346799',
                'month' => '12',
                'year' => '2030',
                'cvv' => '123',
            ],
        ];
    }
}
