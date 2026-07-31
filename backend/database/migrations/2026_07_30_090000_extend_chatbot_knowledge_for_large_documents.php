<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kolom `content` semula TEXT — hanya menampung ±65 KB, sehingga dokumen
     * besar terpotong diam-diam (atau ditolak saat mode ketat). LONGTEXT
     * menghapus batas praktis tersebut.
     */
    public function up(): void
    {
        Schema::table('chatbot_knowledge', function (Blueprint $t) {
            $t->longText('content')->change();
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge', function (Blueprint $t) {
            $t->text('content')->change();
        });
    }
};
