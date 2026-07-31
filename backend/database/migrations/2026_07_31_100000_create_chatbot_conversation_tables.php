<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat percakapan + metrik tiap jawaban.
 *
 * Tanpa tabel ini tidak ada satu pun data untuk dipantau: kita tidak tahu apa
 * yang ditanyakan pegawai, seberapa cepat dijawab, berapa biayanya, dan
 * pertanyaan mana yang tidak terjawab karena pengetahuannya belum ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chatbot_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Peran saat percakapan berlangsung — dipakai analitik per peran.
            $t->string('role', 20)->default('staff');
            $t->string('title', 160)->nullable();
            $t->unsignedInteger('message_count')->default(0);
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();

            $t->index(['user_id', 'last_message_at']);
            $t->index('last_message_at');
        });

        Schema::create('chatbot_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->constrained('chatbot_conversations')->cascadeOnDelete();
            $t->string('role', 16);                 // user | assistant
            $t->longText('content');

            // Hasil pemrosesan jawaban:
            //   answered   - dijawab dengan konteks pengetahuan
            //   no_context - tidak ada pengetahuan yang cocok (celah basis data)
            //   fallback   - API gagal, dibalas pesan cadangan
            $t->string('outcome', 20)->nullable();

            $t->unsignedInteger('latency_ms')->nullable();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->string('model', 60)->nullable();

            // Judul dokumen yang benar-benar dipakai menjawab.
            $t->json('sources')->nullable();

            // Umpan balik pegawai: up | down
            $t->string('feedback', 8)->nullable();

            $t->timestamps();

            $t->index(['conversation_id', 'id']);
            $t->index(['role', 'created_at']);
            $t->index('outcome');
            $t->index('feedback');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chatbot_messages');
        Schema::dropIfExists('chatbot_conversations');
    }
};
