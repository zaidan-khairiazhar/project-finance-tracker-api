<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\WalletController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferController;

Route::get('/', function () {
    return response()->json(['message' => 'Finance API is running.']);
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('wallets', WalletController::class);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('wallets', WalletController::class);
    Route::apiResource('categories', CategoryController::class)->except('show');
});

Route::apiResource('transactions', TransactionController::class);

Route::post('transfers', [TransferController::class, 'store']);
Route::delete('transfers/{groupId}', [TransferController::class, 'destroy'])->whereUuid('groupId');