<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    private function form(array $overrides = []): array
    {
        return array_replace(['name' => 'Dosen', 'email' => 'dosen@example.com', 'password' => 'rahasia-panjang', 'password_confirmation' => 'rahasia-panjang'], $overrides);
    }

    public function test_guest_can_register_and_is_logged_in(): void
    {
        $this->get('/register')->assertOk()->assertSee('Buat akun');

        $this->post('/register', $this->form())->assertRedirect(route('stations'));

        $user = User::query()->where('email', 'dosen@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('rahasia-panjang', $user->password));
    }

    public function test_rejects_short_mismatched_or_duplicate_registration(): void
    {
        $this->post('/register', $this->form(['password' => 'pendek', 'password_confirmation' => 'pendek']))->assertSessionHasErrors('password');
        $this->post('/register', $this->form(['password_confirmation' => 'beda-sekali-ya']))->assertSessionHasErrors('password');
        User::factory()->create(['email' => 'dosen@example.com']);
        $this->post('/register', $this->form())->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_is_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/register', $this->form(['email' => "user{$i}@example.com"]));
            auth()->logout();
        }

        $this->post('/register', $this->form(['email' => 'lagi@example.com']))->assertSessionHasErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'lagi@example.com']);
    }
}
