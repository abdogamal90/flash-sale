<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductController;


Route::get('/', function () {
    return response()->json([
        'message' => 'Welcome to the API',
        'status' => 'success',
        'timestamp' => now()->toISOString()
    ]);
});

// product routes

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}', [ProductController::class, 'show']);
Route::post('/products', [ProductController::class, 'create']);
Route::post('/products/{id}/buy', [ProductController::class, 'buy']);

// hold routes
Route::get('/holds', [App\Http\Controllers\HoldController::class, 'index']);
Route::get('/holds/{id}', [App\Http\Controllers\HoldController::class, 'show']);
Route::post('/holds', [App\Http\Controllers\HoldController::class, 'create']);
