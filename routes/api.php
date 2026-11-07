<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json(['message' => 'Finance API is running.']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');