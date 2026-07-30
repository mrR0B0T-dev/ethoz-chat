<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Login sesi minimal untuk backend mandiri ini.
 *
 * Di lingkungan sesungguhnya, sesi login berasal dari aplikasi Ethoz yang
 * SUDAH ADA (lihat IMPLEMENTATION.md — "cukup login Ethoz"). Controller ini
 * hanya pengganti agar konsol admin §11 bisa dibuka saat backend dijalankan
 * sendiri; hapus bila sudah disatukan dengan autentikasi Ethoz.
 */
class LoginController extends Controller
{
    public function show()
    {
        return Auth::check()
            ? redirect()->intended(route('admin.chatbot'))
            : view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak cocok.',
            ]);
        }

        // Cegah session fixation setelah pergantian identitas.
        $request->session()->regenerate();

        return redirect()->intended(route('admin.chatbot'));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
