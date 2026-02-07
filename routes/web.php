<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/create-token', function () {
    $user = \App\Models\User::first();
    $token = $user->createToken('api-token')->plainTextToken;

    return ['token' => $token];
});

Route::get('/test', function () {
    dd(1);
});
