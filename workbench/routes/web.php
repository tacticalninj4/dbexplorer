<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

Route::get('/', function () {
    return view('welcome');
});

// Workbench only: sign in as the seeded user, so `composer serve` reaches the explorer.
Route::get('/login', function () {
    Auth::login(User::query()->firstOrFail());

    return redirect('/dbexplorer');
})->name('login');
