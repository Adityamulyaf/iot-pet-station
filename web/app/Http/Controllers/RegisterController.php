<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function show(): View
    {
        return view('auth.register');
    }

    public function store(Request $request): RedirectResponse
    {
        $key = 'register:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['email' => 'Terlalu banyak pendaftaran dari jaringan ini. Coba lagi nanti.']);
        }
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:10|confirmed',
        ], [
            'email.unique' => 'Email ini sudah terdaftar. Silakan masuk.',
            'password.min' => 'Kata sandi minimal 10 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
        ]);
        RateLimiter::hit($key, 3600);

        $user = User::query()->create($data);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('stations');
    }
}
