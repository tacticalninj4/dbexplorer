<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

// Loaded by DbExplorerServiceProvider inside the configured domain, prefix and
// middleware group, and only while dbexplorer.enabled is true.

Route::get('api/ping', fn (): JsonResponse => new JsonResponse(['ok' => true]))->name('ping');
