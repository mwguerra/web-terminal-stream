<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * E2E-only backdoor login. Gated on E2E_TESTING so it can never exist in a
 * real deployment; Playwright's global-setup hits it once and persists the
 * session as storageState.
 */
Route::get('/e2e/login', function () {
    abort_unless((bool) env('E2E_TESTING'), 404);

    Auth::login(User::query()->firstOrFail());

    return redirect('/admin');
});
