<?php

use App\Http\Controllers\PaymentTestController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

/*
| AnadoluPay ödeme önizlemesi
|
| Bankanın 3D dönüşü POST ile gelir ve CSRF token taşımaz; bu yüzden
| callback rotası CSRF doğrulamasından muaf tutulur. Güvenlik, paketin
| imza doğrulamasıyla sağlanır.
*/
Route::get('/payment', [PaymentTestController::class, 'index'])->name('payment.preview');
Route::post('/payment', [PaymentTestController::class, 'pay'])->name('payment.pay');
Route::get('/payment/status', [PaymentTestController::class, 'status'])->name('payment.status');

Route::match(['get', 'post'], '/payment/callback', [PaymentTestController::class, 'callback'])
    ->withoutMiddleware([Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->name('payment.callback');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

require __DIR__.'/settings.php';
