<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

// Halaman depan langsung menuju konsol admin. Dibuat sebagai redirect, bukan
// view() langsung: dengan redirect, middleware auth + gate 'manage-chatbot'
// pada /admin/chatbot tetap berlaku, dan tamu diarahkan ke halaman login.
Route::redirect('/', '/admin/chatbot');

// Login sesi — pengganti sementara agar backend mandiri ini bisa dipakai.
// Di produksi, sesi datang dari autentikasi Ethoz yang sudah ada.
// Route bernama 'login' juga dibutuhkan middleware auth untuk mengarahkan tamu.
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('login.attempt');
});

Route::post('/logout', [LoginController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// Chatbot untuk klien web Ethoz — pakai cookie sesi login yang sudah ada.
Route::middleware('auth')->group(base_path('routes/chatbot.php'));

// Halaman konsol admin (Blade). Sengaja hanya di sisi web — halaman HTML tidak
// relevan untuk routes/api.php yang dipakai aplikasi mobile lewat token Sanctum.
Route::middleware(['auth', 'can:manage-chatbot'])
    ->get('/admin/chatbot', fn () => view('admin.chatbot'))
    ->name('admin.chatbot');
