<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use TacticalNinj4\DbExplorer\Http\Controllers\AssetController;
use TacticalNinj4\DbExplorer\Http\Controllers\ConfirmPasswordController;
use TacticalNinj4\DbExplorer\Http\Controllers\MeController;
use TacticalNinj4\DbExplorer\Http\Controllers\ShellController;

// Loaded by DbExplorerServiceProvider inside the configured domain, prefix and
// middleware group, and only while dbexplorer.enabled is true.

Route::get('api/me', MeController::class)->name('me');

// Password re-confirmation for destructive actions (S5), brute-force limited.
Route::post('api/confirm-password', ConfirmPasswordController::class)
    ->middleware(ThrottleRequests::class.':dbexplorer-confirm')
    ->name('confirm-password');

Route::get('assets/{path}', AssetController::class)->where('path', '.+')->name('asset');

// The SPA shell answers every other URL so client-side routes survive a
// reload. It stays last, and never matches under api/ or assets/, so unknown
// API calls get a JSON 404 rather than HTML.
Route::get('{view?}', ShellController::class)
    ->where('view', '(?!(?:api|assets)(?:/|$)).*')
    ->name('shell');
