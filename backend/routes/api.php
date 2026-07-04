<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\PhotoController;
use App\Http\Controllers\Api\V1\RecordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth
    Route::post('/auth/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // Customers
        Route::apiResource('customers', CustomerController::class);

        // Records
        Route::get('customers/{customerId}/records', [RecordController::class, 'index']);
        Route::post('customers/{customerId}/records', [RecordController::class, 'store']);
        Route::get('records/{recordId}', [RecordController::class, 'show']);
        Route::post('records/{recordId}/summarize', [RecordController::class, 'summarize']);
        Route::patch('records/{recordId}', [RecordController::class, 'update']);
        Route::delete('records/{recordId}', [RecordController::class, 'destroy']);

        // Photos
        Route::post('records/{recordId}/photos', [PhotoController::class, 'store']);
        Route::delete('photos/{photoId}', [PhotoController::class, 'destroy']);
    });
});
