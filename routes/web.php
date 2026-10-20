<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\OnboardController;
use App\Http\Controllers\PasswordController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'landing');

// Emailed invitation link. The token is the only identifier in a URL; it is moved into the server-side session at once.
Route::get('/onboard/{token}', [OnboardController::class, 'show'])
    ->where('token', '.{10,2000}')->middleware('throttle:30,1')->name('onboard');
Route::get('/link-invalid', [OnboardController::class, 'invalid'])->name('link.invalid');

Route::get('/sign-in', [AuthController::class, 'create'])->name('sign-in');
Route::post('/sign-in', [AuthController::class, 'store'])->middleware('throttle:sign-in');

Route::middleware('vendor')->group(function () {
    Route::get('/home', HomeController::class)->name('home');
    Route::get('/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::post('/password', [PasswordController::class, 'update'])->name('password.update');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});
