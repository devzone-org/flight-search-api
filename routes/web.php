<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    dd(1);
    return view('welcome');
});

Route::get('/create-token', function () {
    $user = \App\Models\User::first();
    $token = $user->createToken('api-token')->plainTextToken;

    return ['token' => $token];
});
