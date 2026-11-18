<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StationUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_account_with_hashed_password(): void
    {
        $this->artisan('station:user', ['email' => 'owner@example.com', 'name' => 'Pemilik'])
            ->expectsQuestion('Kata sandi (minimal 10 karakter)', 'rahasia-panjang')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('rahasia-panjang', $user->password));
    }

    public function test_rejects_short_or_mismatched_password(): void
    {
        $this->artisan('station:user', ['email' => 'owner@example.com', 'name' => 'Pemilik'])
            ->expectsQuestion('Kata sandi (minimal 10 karakter)', 'pendek')
            ->assertFailed();
        $this->artisan('station:user', ['email' => 'owner@example.com', 'name' => 'Pemilik'])
            ->expectsQuestion('Kata sandi (minimal 10 karakter)', 'rahasia-panjang')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-lain-x')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.com']);
    }

    public function test_resets_password_of_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->artisan('station:user', ['email' => 'owner@example.com', 'name' => 'Pemilik baru'])
            ->expectsQuestion('Kata sandi (minimal 10 karakter)', 'rahasia-baru-123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-baru-123')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('rahasia-baru-123', $user->fresh()->password));
        $this->assertSame(1, User::query()->count());
    }
}
