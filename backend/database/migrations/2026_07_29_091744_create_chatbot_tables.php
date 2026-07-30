<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Pengaturan bot — satu baris konfigurasi
        Schema::create('chatbot_settings', function (Blueprint $t) {
            $t->id();
            $t->string('bot_name')->default('Ethoz Chat');
            $t->string('company')->default('PT Bumi Daya Plaza');
            $t->text('role')->nullable();
            $t->string('tone')->default('ramah');        // formal | ramah | santai
            $t->string('address')->default('Kamu');       // Anda | Kamu
            $t->boolean('emoji')->default(false);
            $t->boolean('allow_bullets')->default(true);
            $t->text('extra')->nullable();
            $t->boolean('no_hallucination')->default(true);
            $t->boolean('protect_sensitive')->default(true);
            $t->string('max_length')->default('detail');  // singkat | sedang | detail
            $t->string('language')->default('id');        // id | en | follow
            $t->text('blocked_topics')->nullable();
            $t->timestamps();
        });

        // Basis pengetahuan
        Schema::create('chatbot_knowledge', function (Blueprint $t) {
            $t->id();
            $t->string('title');
            $t->text('content');
            $t->string('scope')->default('all');          // all | hr | manager | hr_manager
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chatbot_knowledge');
        Schema::dropIfExists('chatbot_settings');
    }
};
