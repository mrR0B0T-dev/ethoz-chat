<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Pegawai biasa — hanya bisa memakai chat, bukan mengelolanya.
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Admin — memenuhi Gate 'manage-chatbot' agar /admin/chatbot bisa dibuka.
        // Kata sandi bawaan factory: "password".
        User::factory()->create([
            'name' => 'Admin Ethoz',
            'email' => 'admin@example.com',
            'role' => 'admin',
        ]);
    }
}
