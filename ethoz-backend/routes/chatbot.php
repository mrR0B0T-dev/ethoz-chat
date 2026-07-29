<?php

use App\Http\Controllers\Admin\ChatbotDocumentController;
use App\Http\Controllers\Admin\ChatbotKnowledgeController;
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

// Sisi admin — login + izin kelola chatbot.
Route::middleware('can:manage-chatbot')->prefix('admin/chatbot')->group(function () {
    Route::get('settings', [ChatbotSettingController::class, 'show'])->name('chatbot.settings.show');
    Route::put('settings', [ChatbotSettingController::class, 'update'])->name('chatbot.settings.update');
    Route::post('documents', [ChatbotDocumentController::class, 'store'])->name('chatbot.documents.store');
    Route::apiResource('knowledge', ChatbotKnowledgeController::class)
        ->only(['index', 'store', 'update', 'destroy']);
});
