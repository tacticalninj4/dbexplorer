<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Route;
use TacticalNinj4\DbExplorer\Http\Controllers\AssetController;
use TacticalNinj4\DbExplorer\Http\Controllers\ConfirmPasswordController;
use TacticalNinj4\DbExplorer\Http\Controllers\DdlController;
use TacticalNinj4\DbExplorer\Http\Controllers\MeController;
use TacticalNinj4\DbExplorer\Http\Controllers\ObjectsController;
use TacticalNinj4\DbExplorer\Http\Controllers\QueryController;
use TacticalNinj4\DbExplorer\Http\Controllers\RowsController;
use TacticalNinj4\DbExplorer\Http\Controllers\RowWriteController;
use TacticalNinj4\DbExplorer\Http\Controllers\ShellController;

// Loaded by DbExplorerServiceProvider inside the configured domain, prefix and
// middleware group, and only while dbexplorer.enabled is true.

Route::get('api/me', MeController::class)->name('me');

// Password re-confirmation for destructive actions (S5), brute-force limited.
Route::post('api/confirm-password', ConfirmPasswordController::class)
    ->middleware(ThrottleRequests::class.':dbexplorer-confirm')
    ->name('confirm-password');

// Per-connection APIs. ResolveRole turns {label} into the user's role and the
// Laravel connection for it; the dbexplorer limiter applies per user (S6).
// Schema, object and function names are single path segments: clients
// rawurlencode them, so dots, spaces, quotes and non-ASCII names all work.
// A name containing "/" can't be addressed, as the router decodes %2F first.
Route::prefix('api/{label}')->middleware('throttle:dbexplorer')->group(function (): void {
    Route::get('schemas', [ObjectsController::class, 'schemas'])->name('schemas');
    Route::get('extensions', [ObjectsController::class, 'extensions'])->name('extensions');
    Route::get('search', [ObjectsController::class, 'search'])->name('search');
    Route::get('schemas/{schema}/objects', [ObjectsController::class, 'index'])->name('objects');
    Route::get('objects/{schema}/{name}', [ObjectsController::class, 'show'])->name('object');
    Route::get('objects/{schema}/{name}/columns', [ObjectsController::class, 'columns'])->name('object.columns');
    Route::get('objects/{schema}/{name}/indexes', [ObjectsController::class, 'indexes'])->name('object.indexes');
    Route::get('objects/{schema}/{name}/constraints', [ObjectsController::class, 'constraints'])->name('object.constraints');
    Route::get('objects/{schema}/{name}/triggers', [ObjectsController::class, 'triggers'])->name('object.triggers');
    Route::get('objects/{schema}/{name}/ddl', [ObjectsController::class, 'ddl'])->name('object.ddl');
    Route::get('objects/{schema}/{name}/stats', [ObjectsController::class, 'stats'])->name('object.stats');
    Route::get('objects/{schema}/{name}/definition', [ObjectsController::class, 'definition'])->name('object.definition');
    Route::get('functions/{schema}/{signature}', [ObjectsController::class, 'showFunction'])->name('function');
    Route::get('rows/{schema}/{name}', RowsController::class)->name('rows');
    Route::post('rows/{schema}/{name}', [RowWriteController::class, 'insert'])->name('rows.insert');
    Route::patch('rows/{schema}/{name}', [RowWriteController::class, 'update'])->name('rows.update');
    Route::delete('rows/{schema}/{name}', [RowWriteController::class, 'delete'])->name('rows.delete');
    Route::post('query', [QueryController::class, 'run'])->name('query');
    Route::get('history', [QueryController::class, 'history'])->name('history');
    Route::post('ddl/preview', [DdlController::class, 'preview'])->name('ddl.preview');
    Route::post('ddl/execute', [DdlController::class, 'execute'])->name('ddl.execute');
});

Route::get('assets/{path}', AssetController::class)->where('path', '.+')->name('asset');

// The SPA shell answers every other URL so client-side routes survive a
// reload. It stays last, and never matches under api/ or assets/, so unknown
// API calls get a JSON 404 rather than HTML.
Route::get('{view?}', ShellController::class)
    ->where('view', '(?!(?:api|assets)(?:/|$)).*')
    ->name('shell');
