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

    Route::post('/flights/quote', [FlightSearchController::class, 'quoteRequest']);
    Route::post('/flights/book', [FlightSearchController::class, 'bookRequest']);

});

Route::get('/fire-event', function () {
    broadcast(new \App\Events\SupplierDataBroadcast([
        'supplier_id' => 1,
        'price' => rand(100, 500),
    ]));

    return 'Event Fired';
});
