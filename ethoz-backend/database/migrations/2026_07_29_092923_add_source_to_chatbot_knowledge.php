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
        Schema::table('chatbot_knowledge', function (Blueprint $t) {
            $t->string('source')->default('manual');   // manual | document
            $t->string('file_name')->nullable();
            $t->integer('char_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chatbot_knowledge', function (Blueprint $t) {
            $t->dropColumn(['source', 'file_name', 'char_count']);
        });
    }
};
