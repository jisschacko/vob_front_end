<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankVerificationController;
use App\Http\Controllers\CashfreeWebhookController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\TotController;
use App\Http\Controllers\TotDocumentController;
use App\Http\Controllers\TotListController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::view('/', 'landing');

// Emailed invitation link. The token is the only identifier in a URL; it is moved into the server-side session at once.
Route::get('/onboard/{token}', [OnboardController::class, 'show'])
    ->where('token', '.{10,2000}')->middleware('throttle:30,1')->name('onboard');
Route::get('/link-invalid', [OnboardController::class, 'invalid'])->name('link.invalid');

// Returning vendors: e-mail + password, no invitation link
Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'loginStore'])->middleware('throttle:sign-in');
Route::get('/sign-in', [AuthController::class, 'create'])->name('sign-in');
Route::post('/sign-in', [AuthController::class, 'store'])->middleware('throttle:sign-in');

// Cashfree calls this with no browser session; it is not a form, so no CSRF token (see bootstrap/app.php) and no session is started.
Route::post('/webhooks/cashfree', CashfreeWebhookController::class)->middleware('throttle:120,1')->withoutMiddleware([
    ValidateCsrfToken::class,
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
])->name('webhooks.cashfree');

Route::middleware('vendor')->group(function () {
    Route::get('/tots', [TotListController::class, 'index'])->name('tots.index');
    Route::post('/tots/select', [TotListController::class, 'select'])->name('tots.select');
    Route::post('/bank/verify', [BankVerificationController::class, 'create'])->middleware('throttle:6,1')->name('bank.create');
    Route::get('/bank/verify', [BankVerificationController::class, 'show'])->name('bank.verify');
    Route::post('/bank/verify/status', [BankVerificationController::class, 'status'])->middleware('throttle:60,1')->name('bank.status');
    Route::get('/home', [TotController::class, 'show'])->name('home');
    Route::post('/tot/draft', [TotController::class, 'save'])->name('tot.draft');
    Route::post('/tot/submit', [TotController::class, 'submit'])->middleware('throttle:10,1')->name('tot.submit');
    Route::post('/tot/autosave', [TotController::class, 'autosave'])->middleware('throttle:60,1')->name('tot.autosave');
    Route::post('/tot/documents', [TotDocumentController::class, 'store'])->middleware('throttle:20,1')->name('tot.documents.store');
    Route::post('/tot/documents/remove', [TotDocumentController::class, 'destroy'])->name('tot.documents.destroy');
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::post('/password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
