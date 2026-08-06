<?php

use App\Http\Controllers\Admin\ChatbotDocumentController;
use App\Http\Controllers\Admin\ChatbotKnowledgeController;
use App\Http\Controllers\Admin\ChatbotMonitorController;
use App\Http\Controllers\Admin\ChatbotSettingController;
use App\Http\Controllers\ChatbotController;
use Illuminate\Support\Facades\Route;

/*
 * Rute chatbot dipakai dua kali: lewat web.php (sesi login Ethoz) dan
 * api.php (token Sanctum untuk mobile). Middleware auth ditentukan oleh
 * grup pemanggil, jadi berkas ini hanya mengurus path + izin.
 */

// Sisi pegawai — cukup login Ethoz. throttle mencegah penyalahgunaan.
Route::post('/chatbot/send', [ChatbotController::class, 'send'])
    ->middleware('throttle:30,1')
    ->name('chatbot.send');

// Jawaban mengalir (SSE) — pengalaman utama di aplikasi pegawai.
Route::post('/chatbot/stream', [ChatbotController::class, 'stream'])
    ->middleware('throttle:30,1')
    ->name('chatbot.stream');

// Riwayat percakapan milik pegawai sendiri.
Route::get('/chatbot/conversations', [ChatbotController::class, 'conversations'])
    ->name('chatbot.my-conversations');
Route::get('/chatbot/conversations/{conversation}', [ChatbotController::class, 'conversation'])
    ->name('chatbot.my-conversation');

// Penilaian jawaban oleh pegawai — sumber data mutu di halaman pemantauan.
Route::post('/chatbot/messages/{message}/feedback', [ChatbotController::class, 'feedback'])
    ->middleware('throttle:60,1')
    ->name('chatbot.feedback');

// Sisi admin — login + izin kelola chatbot.
Route::middleware('can:manage-chatbot')->prefix('admin/chatbot')->group(function () {
    Route::get('settings', [ChatbotSettingController::class, 'show'])->name('chatbot.settings.show');
    Route::put('settings', [ChatbotSettingController::class, 'update'])->name('chatbot.settings.update');
    Route::get('preview', [ChatbotSettingController::class, 'preview'])->name('chatbot.settings.preview');
    Route::post('documents', [ChatbotDocumentController::class, 'store'])->name('chatbot.documents.store');

    // Dipantau konsol selagi ada dokumen yang masih diekstrak/di-OCR.
    Route::get('documents/status', [ChatbotDocumentController::class, 'status'])
        ->name('chatbot.documents.status');
    Route::apiResource('knowledge', ChatbotKnowledgeController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    // Pemantauan kinerja.
    Route::get('metrics', [ChatbotMonitorController::class, 'metrics'])->name('chatbot.metrics');
    Route::get('conversations', [ChatbotMonitorController::class, 'conversations'])->name('chatbot.conversations');
    Route::get('conversations/{conversation}', [ChatbotMonitorController::class, 'conversation'])
        ->name('chatbot.conversation');
});
