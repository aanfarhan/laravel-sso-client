<?php

use Illuminate\Support\Facades\Route;
use Mdigi\LaravelSsoClient\Controllers\SsoController;

Route::get('redirect', [SsoController::class, 'redirect'])->name('redirect');
Route::get('callback', [SsoController::class, 'callback'])->name('callback');
Route::post('logout', [SsoController::class, 'ssoLogout'])
    ->middleware('auth')
    ->name('logout');
Route::post('local-logout', [SsoController::class, 'localLogout'])
    ->middleware('auth')
    ->name('local-logout');