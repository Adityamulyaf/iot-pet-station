<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Models\User;
use Illuminate\Console\Command;

class StationProvision extends Command
{
    protected $signature = 'station:provision {device} {email} {name} {--physical}';

    protected $description = 'Register a station and assign its owner; broker credentials are provisioned separately';

    public function handle(): int
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $this->argument('device'))) {
            $this->error('Identitas tidak valid.');

            return self::FAILURE;
        }
        $user = User::query()->where('email', $this->argument('email'))->first();
        if (! $user) {
            $this->error('Akun tidak ditemukan.');

            return self::FAILURE;
        }
        if (Station::query()->where('device_id', $this->argument('device'))->exists()) {
            $this->error('Perangkat sudah terdaftar.');

            return self::FAILURE;
        }
        Station::query()->create(['user_id' => $user->id, 'device_id' => $this->argument('device'), 'name' => $this->argument('name'), 'simulated' => ! $this->option('physical')]);
        $this->info('Station terdaftar. Provision kredensial dan ACL broker sebelum menghubungkan perangkat.');

        return self::SUCCESS;
    }
}
