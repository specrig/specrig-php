<?php

use App\Http\Controllers\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
Route::post('/orders', [OrderController::class, 'store']);
