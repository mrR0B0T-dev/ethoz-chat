<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_tamu_diarahkan_ke_login_bukan_error_500(): void
    {
        // Tanpa route bernama 'login', middleware auth melempar
        // RouteNotFoundException dan halaman admin balas 500.
        $this->get('/admin/chatbot')->assertRedirect('/login');
    }

    public function test_halaman_login_bisa_dibuka(): void
    {
        $this->get('/login')->assertOk()->assertSee('Masuk');
    }

    public function test_admin_bisa_masuk_dan_membuka_konsol(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com', 'role' => 'admin']);

        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])
            ->assertRedirect(route('admin.chatbot'));

        $this->assertAuthenticatedAs($admin);
        $this->get('/admin/chatbot')->assertOk();
    }

    public function test_kata_sandi_salah_ditolak(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'role' => 'admin']);

        $this->from('/login')
            ->post('/login', ['email' => 'admin@example.com', 'password' => 'salah'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_pegawai_biasa_masuk_tapi_konsol_tetap_tertutup(): void
    {
        User::factory()->create(['email' => 'staff@example.com', 'role' => 'staff']);

        $this->post('/login', ['email' => 'staff@example.com', 'password' => 'password']);
        $this->assertAuthenticated();

        $this->get('/admin/chatbot')->assertForbidden();
    }

    public function test_pengguna_bisa_keluar(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_pengguna_yang_sudah_masuk_tidak_melihat_form_login(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get('/login')->assertRedirect();
    }
}
