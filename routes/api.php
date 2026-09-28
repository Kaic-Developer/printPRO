<?php

use App\Http\Controllers\Api\QuoteApiController;
use App\Http\Controllers\Api\TokenController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::post('/auth/token', [TokenController::class, 'store'])->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function (): void {
        Route::delete('/auth/token', [TokenController::class, 'destroy']);
        Route::get('/user', fn (Request $request) => $request->user());
        Route::get('/quote-presets', [QuoteApiController::class, 'catalog'])->middleware('ability:catalog:read');
        Route::get('/quote-settings', [QuoteApiController::class, 'catalog'])->middleware('ability:catalog:read');
        Route::patch('/quote-settings', [QuoteApiController::class, 'updateSettings'])->middleware('ability:catalog:write');
        Route::get('/quotes', [QuoteApiController::class, 'quotes'])->middleware('ability:quotes:read');
        Route::post('/quotes', [QuoteApiController::class, 'store'])->middleware('ability:quotes:write');
        Route::get('/quotes/{quote}', [QuoteApiController::class, 'show'])->whereNumber('quote')->middleware('ability:quotes:read');
        Route::post('/quotes/{quote}/versions', [QuoteApiController::class, 'revise'])->whereNumber('quote')->middleware('ability:quotes:write');
        Route::post('/quotes/{quote}/approve', [QuoteApiController::class, 'approve'])->whereNumber('quote')->middleware('ability:quotes:write');
        Route::get('/production-orders', [QuoteApiController::class, 'productionOrders'])->middleware('ability:production:read');
        Route::post('/nesting-estimates', [QuoteApiController::class, 'nesting'])->middleware('ability:quotes:write');
    });
});
