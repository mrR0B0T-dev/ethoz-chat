<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Halaman depan mengarahkan ke konsol admin, bukan menampilkannya langsung,
     * agar middleware auth + gate di /admin/chatbot tetap berlaku.
     */
    public function test_halaman_depan_diarahkan_ke_konsol_admin(): void
    {
        $this->get('/')->assertRedirect('/admin/chatbot');
    }

    /** Tamu yang membuka halaman depan berakhir di login, bukan di konsol. */
    public function test_tamu_dari_halaman_depan_berakhir_di_login(): void
    {
        $this->get('/')->assertRedirect('/admin/chatbot');
        $this->get('/admin/chatbot')->assertRedirect('/login');
    }
}
