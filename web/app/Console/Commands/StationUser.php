<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class StationUser extends Command
{
    protected $signature = 'station:user {email} {name}';

    protected $description = 'Create an owner account or reset its password; the password is asked interactively';

    public function handle(): int
    {
        if (! filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL)) {
            $this->error('Email tidak valid.');

            return self::FAILURE;
        }
        $password = (string) $this->secret('Kata sandi (minimal 10 karakter)');
        if (mb_strlen($password) < 10) {
            $this->error('Kata sandi minimal 10 karakter.');

            return self::FAILURE;
        }
        if ($password !== $this->secret('Ulangi kata sandi')) {
            $this->error('Kata sandi tidak sama.');

            return self::FAILURE;
        }
        $user = User::query()->updateOrCreate(['email' => $this->argument('email')], ['name' => $this->argument('name'), 'password' => $password]);
        $this->info($user->wasRecentlyCreated ? 'Akun dibuat.' : 'Kata sandi akun diperbarui.');

        return self::SUCCESS;
    }
}
