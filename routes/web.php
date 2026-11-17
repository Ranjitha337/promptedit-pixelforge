<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Controllers\GenerationController;


Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

Route::get('dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class);
Route::get('/generations/history', [
    GenerationController::class,
    'history',
])->middleware('auth')->name('generations.history');

Route::get('/generations', function () {
    return Inertia::render('Generations/Index');
})->middleware('auth')->name('generations.index');

Route::get('/generations/estimate', [
    GenerationController::class,
    'estimate',
])->middleware('auth')->name('generations.estimate');

Route::post('/generations', [
    GenerationController::class,
    'store',
])->middleware('auth')->name('generations.store');

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
