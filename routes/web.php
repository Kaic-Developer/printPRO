<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ProductController;
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
    Route::resource('customers', CustomerController::class)->except('destroy')->where(['customer' => '[0-9]+']);
    Route::patch('/products/{product}/toggle', [ProductController::class, 'toggle'])->whereNumber('product')->name('products.toggle');
    Route::resource('products', ProductController::class)->except('destroy')->where(['product' => '[0-9]+']);
});
