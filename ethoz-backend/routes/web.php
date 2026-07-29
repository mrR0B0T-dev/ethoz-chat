<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Chatbot untuk klien web Ethoz — pakai cookie sesi login yang sudah ada.
Route::middleware('auth')->group(base_path('routes/chatbot.php'));
