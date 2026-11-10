<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankVerificationController;
use App\Http\Controllers\FieldVerificationController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\TotController;
use App\Http\Controllers\TotDocumentController;
use App\Http\Controllers\TotListController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing');

// Emailed invitation link. The token is the only identifier in a URL; it is moved into the server-side session at once.
Route::get('/onboard/{token}', [OnboardController::class, 'show'])
    ->where('token', '.{10,2000}')->middleware('throttle:30,1')->name('onboard');
Route::get('/link-invalid', [OnboardController::class, 'invalid'])->name('link.invalid');

// Returning vendors: e-mail + password, no invitation link
Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
Route::post('/login', [AuthController::class, 'loginStore'])->middleware('throttle:sign-in');
// Forgot password. The emailed token is moved into the session at once (like /onboard/{token}); the Admin does the rest.
Route::get('/forgot-password', [PasswordResetController::class, 'forgotForm'])->name('password.forgot');
Route::post('/forgot-password', [PasswordResetController::class, 'forgotStore'])->middleware('throttle:10,1')->name('password.email');
Route::get('/reset-password/{token}', [PasswordResetController::class, 'link'])->where('token', '.{10,2000}')->middleware('throttle:30,1')->name('password.reset.link');
Route::get('/reset-password', [PasswordResetController::class, 'resetForm'])->name('password.reset');
Route::post('/reset-password', [PasswordResetController::class, 'resetStore'])->middleware('throttle:10,1')->name('password.reset.store');

Route::get('/sign-in', [AuthController::class, 'create'])->name('sign-in');
Route::post('/sign-in', [AuthController::class, 'store'])->middleware('throttle:sign-in');

Route::middleware('vendor')->group(function () {
    Route::get('/tots', [TotListController::class, 'index'])->name('tots.index');
    Route::post('/tots/select', [TotListController::class, 'select'])->name('tots.select');
    // Background Cashfree checks while the vendor fills the form (POST + CSRF; each call can cost money, hence the throttles)
    Route::post('/verify/pan', [FieldVerificationController::class, 'pan'])->middleware('throttle:30,1')->name('verify.pan');
    Route::post('/verify/gstin', [FieldVerificationController::class, 'gstin'])->middleware('throttle:30,1')->name('verify.gstin');
    Route::post('/verify/ifsc', [FieldVerificationController::class, 'ifsc'])->middleware('throttle:30,1')->name('verify.ifsc');
    Route::post('/verify/bank', [FieldVerificationController::class, 'bank'])->middleware('throttle:10,1')->name('verify.bank');
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
