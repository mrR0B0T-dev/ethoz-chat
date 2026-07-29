<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Chatbot untuk aplikasi mobile — Authorization: Bearer <token Sanctum>.
Route::middleware('auth:sanctum')->name('api.')->group(base_path('routes/chatbot.php'));
