<?php

use App\Http\Controllers\Api\FlightSearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::middleware('auth:sanctum')->group(function () {

    // When POST /api/flights/search is called,
    // Laravel will execute FlightSearchController@search
    Route::post('/flights/search', [FlightSearchController::class, 'search']);

});