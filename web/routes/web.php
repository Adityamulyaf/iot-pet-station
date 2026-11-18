<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RegisterController;
use App\Livewire\StationApp;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'stations' : 'login'));
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->name('login.submit');
    Route::get('/register', [RegisterController::class, 'show'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])->name('register.submit');
});
Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/stations', StationApp::class)->name('stations');
    Route::get('/stations/{station}/{section?}', StationApp::class)->where('section', 'dashboard|history|analytics|settings')->name('station');
});
