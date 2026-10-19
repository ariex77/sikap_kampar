<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\PublicPortalController;
use App\Livewire\Dashboard;
use App\Livewire\Prices\Form as PriceForm;
use App\Livewire\Prices\Index as PriceIndex;
use App\Livewire\Stock\Form as StockForm;
use App\Livewire\Stock\Index as StockIndex;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

// Portal publik: tanpa login, hanya informasi yang sudah terbit. Dibatasi 60 permintaan per menit per alamat.
Route::get('/publik', [PublicPortalController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('public.prices');

// Hanya untuk tamu (belum login)
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    // Maksimal 5 percobaan per menit untuk menahan tebak-tebakan kata sandi
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

// Hanya untuk pengguna yang sudah login
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    // Modul harga pangan
    Route::prefix('harga')->name('prices.')->middleware('can:price.view')->group(function () {
        Route::get('/', PriceIndex::class)->name('index');
        Route::get('/baru', PriceForm::class)->name('create');
        Route::get('/{priceId}/ubah', PriceForm::class)->name('edit')->whereNumber('priceId');
    });

    // Modul stok dan cadangan pangan
    Route::prefix('stok')->name('stock.')->middleware('can:stock.view')->group(function () {
        Route::get('/', StockIndex::class)->name('index');
        Route::get('/baru', StockForm::class)->name('create');
        Route::get('/{movementId}/ubah', StockForm::class)->name('edit')->whereNumber('movementId');
    });
});
