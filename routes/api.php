<?php

use App\Http\Controllers\Api\MobileController;
use Illuminate\Support\Facades\Route;

Route::prefix('mobile')->group(function () {
    Route::get('status', [MobileController::class, 'status']);
    Route::post('pair', [MobileController::class, 'pair']);
    Route::post('chat', [MobileController::class, 'chat']);
    Route::get('conversations', [MobileController::class, 'conversations']);
    Route::get('conversations/{conversationId}/messages', [MobileController::class, 'messages']);
});
