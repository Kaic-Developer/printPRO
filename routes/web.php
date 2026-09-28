<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteSettingsController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route(auth()->check() ? 'dashboard' : 'login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'storeLogin'])->middleware('throttle:20,1')->name('login.store');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'storeRegister'])->middleware('throttle:10,1')->name('register.store');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::get('/finance', [FinanceController::class, 'index'])->name('finance.index');
    Route::get('/finance/entries/create', [FinanceController::class, 'create'])->name('finance.create');
    Route::post('/finance/entries', [FinanceController::class, 'store'])->name('finance.store');
    Route::get('/quote-settings', [QuoteSettingsController::class, 'index'])->name('quote-settings.index');
    Route::match(['put', 'patch'], '/quote-settings', [QuoteSettingsController::class, 'update'])->name('quote-settings.update');
    Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
    Route::get('/quotes/create', [QuoteController::class, 'create'])->name('quotes.create');
    Route::post('/quotes', [QuoteController::class, 'store'])->name('quotes.store');
    Route::post('/quotes/nesting-estimate', [QuoteController::class, 'nesting'])->name('quotes.nesting');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->whereNumber('quote')->name('quotes.show');
    Route::post('/quotes/{quote}/versions', [QuoteController::class, 'revise'])->whereNumber('quote')->name('quotes.revise');
    Route::post('/quotes/{quote}/approve', [QuoteController::class, 'approve'])->whereNumber('quote')->name('quotes.approve');
    Route::resource('customers', CustomerController::class)->except('destroy')->where(['customer' => '[0-9]+']);
    Route::patch('/products/{product}/toggle', [ProductController::class, 'toggle'])->whereNumber('product')->name('products.toggle');
    Route::resource('products', ProductController::class)->except('destroy')->where(['product' => '[0-9]+']);
});
