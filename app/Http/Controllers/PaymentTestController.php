<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
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
            'order_id' => ['nullable', 'string'],
        ]);

        // Sipariş numarasını Volt bileşeni üretir; böylece kullanıcı
        // ödemeyi başlatmadan önce numarayı görebilir ve sonra durum
        // sorgusunda aynı numarayı kullanabilir.
        $orderId = $validated['order_id'] ?: 'TEST-'.strtoupper(Str::random(10));

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
            /*
             * Bağlam dönüş adresinde taşınır, oturumda değil: bankanın
             * POST'u siteler arası olduğu için `SameSite=lax` çerezi
             * gönderilmez ve oturum boş gelir. Sağlayıcı adı olmadan
             * dönüşü hangi driver'ın doğrulayacağı bilinemez.
             */
            successUrl: route('payment.callback', ['driver' => $validated['driver'], 'order' => $orderId]),
            failUrl: route('payment.callback', ['driver' => $validated['driver'], 'order' => $orderId]),
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
            return $this->back(
                $e->outcomeUncertain ? 'Bankaya ulaşıldı ama sonuç belirsiz' : 'Banka isteği reddetti',
                $e->getMessage(),
                $e->context + ['safe_to_retry' => $e->safeToRetry, 'sonuc_belirsiz' => $e->outcomeUncertain],
            );
        } catch (Throwable $e) {
            return $this->back('Beklenmeyen hata', $e->getMessage(), ['class' => $e::class]);
        }

        /*
         * Dönüşte gerekecek bağlamı sipariş numarasıyla önbelleğe alıyoruz.
         * Oturuma güvenilemez: bankanın dönüş POST'u siteler arası olduğu
         * için `SameSite=lax` çerezi gönderilmez. Moka'nın `code_for_hash`
         * değeri olmadan dönüş sonucu okunamaz.
         */
        Cache::put("anadolupay:order:{$orderId}", [
            'driver' => $validated['driver'],
            'order_id' => $orderId,
            'amount' => $validated['amount'],
            'installment' => (int) $validated['installment'],
            'code_for_hash' => $response->raw['code_for_hash'] ?? null,
        ], now()->addMinutes(30));

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
        $orderId = $request->query('order');

        // Önce önbellek (çerezden bağımsız), sonra oturum.
        $context = ($orderId !== null ? Cache::get("anadolupay:order:{$orderId}") : null)
            ?? session('payment_context', []);

        $driver = $request->query('driver') ?? $context['driver'] ?? 'iyzico';

        /*
         * Yalnızca POST gövdesi: `all()` sorgu dizgisindeki `driver` ve
         * `order` parametrelerini de katardı ve bankanın imzalamadığı bu
         * alanlar hash doğrulamasını bozardı.
         */
        $payload = $request->post();

        try {
            $result = AnadoluPay::driver($driver)->verify(new VerifyPaymentData(
                payload: $payload,
                headers: $request->headers->all(),
                rawBody: $request->getContent(),
                order: [
                    // Oturum gelmediğinde sipariş numarası da adresten okunur.
                    'id' => $request->query('order') ?? $context['order_id'] ?? null,
                    'amount' => $context['amount'] ?? null,
                    'currency' => 'TRY',
                    'installment' => $context['installment'] ?? 1,
                    'ip' => $request->ip(),
                    // Moka dönüş sonucunu yalnızca bu değerle okuyabilir.
                    'code_for_hash' => $context['code_for_hash'] ?? null,
                ],
            ));
        } catch (InvalidSignatureException $e) {
            return $this->result($driver, 'İmza doğrulanamadı', false, $payload, $e->context);
        } catch (TransportException $e) {
            return $this->result(
                $driver,
                $e->outcomeUncertain ? 'Bankaya ulaşıldı ama sonuç belirsiz' : 'Banka isteği reddetti — işlem gerçekleşmedi',
                false,
                $payload,
                $e->context + ['sonuc_belirsiz' => $e->outcomeUncertain],
            );
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
                'order_id' => $request->query('order') ?? $context['order_id'] ?? null,
            ],
            raw: $result->raw,
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function back(string $title, string $message, array $context = [])
    {
        Log::warning('AnadoluPay önizleme hatası', ['title' => $title, 'message' => $message]);

        return redirect()->route('payment.preview')->with('payment_error', [
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
}
