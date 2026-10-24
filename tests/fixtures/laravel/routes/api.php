<?php

use App\Http\NoteController;
use Illuminate\Support\Facades\Route;

Route::get('/accounts/{id?}', fn () => []);

Route::prefix('admin')->middleware('auth')->group(function () {
    Route::match(['GET', 'POST'], '/letters', fn () => []);
    Route::apiResource('notes', NoteController::class)->only(['index', 'show']);
});
