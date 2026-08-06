<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ekstraksi dokumen kini berjalan di antrean (OCR bisa menit-menitan),
     * sehingga satu entri bisa ada sebelum isinya siap. Tanpa kolom status,
     * entri kosong itu tidak terbedakan dari entri gagal — dan admin tidak
     * punya cara melihat kenapa dokumennya belum muncul.
     *
     * Bawaannya 'done' supaya seluruh entri lama dan entri manual (yang isinya
     * langsung ada) tetap terhitung siap pakai tanpa penyesuaian apa pun.
     */
    public function up(): void
    {
        Schema::table('chatbot_knowledge', function (Blueprint $t) {
            // queued | processing | done | failed
            $t->string('status', 20)->default('done')->index();

            // Alasan gagal / catatan hasil ekstraksi, ditampilkan ke admin.
            $t->text('status_message')->nullable();

            $t->timestamp('processed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('chatbot_knowledge', function (Blueprint $t) {
            $t->dropIndex(['status']);
            $t->dropColumn(['status', 'status_message', 'processed_at']);
        });
    }
};
