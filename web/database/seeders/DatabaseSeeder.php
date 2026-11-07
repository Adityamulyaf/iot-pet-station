<?php

namespace Database\Seeders;

use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }
        $user = User::query()->firstOrCreate(['email' => 'demo@petstation.local'], ['name' => 'Pemilik station', 'password' => Hash::make('petstation-local')]);
        Station::query()->firstOrCreate(['device_id' => 'station-001'], ['user_id' => $user->id, 'name' => 'Station ruang keluarga', 'location' => 'Ruang keluarga']);
        Station::query()->firstOrCreate(['device_id' => 'station-002'], ['user_id' => $user->id, 'name' => 'Station teras', 'location' => 'Teras belakang']);
    }
}
