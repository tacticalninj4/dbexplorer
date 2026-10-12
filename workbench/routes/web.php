<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use TacticalNinj4\DbExplorer\Role;
use Workbench\App\Models\User;
use Workbench\App\Providers\WorkbenchServiceProvider;

Route::get('/', function () {
    return view('welcome');
});

// Workbench only: sign in as a seeded user, so `composer serve` reaches the
// explorer. /login is the admin; /login/editor and /login/viewer the others.
Route::get('/login/{role?}', function (Request $request, string $role = Role::ADMIN) {
    $email = array_search($role, WorkbenchServiceProvider::USERS, true);
    abort_if($email === false, 404);

    Auth::login(User::query()->where('email', $email)->firstOrFail());
    $request->session()->regenerate();

    return redirect('/dbexplorer');
})->name('login');

// Workbench only: end the session the way a host app's logout would.
Route::get('/logout', function (Request $request) {
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return response('Signed out. Sign in again at /login, /login/editor or /login/viewer.', 200, ['Content-Type' => 'text/plain']);
})->name('logout');
