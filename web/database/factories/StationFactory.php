<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class StationFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'device_id' => 'station-'.fake()->unique()->numerify('######'), 'name' => 'Station ruang keluarga', 'location' => 'Ruang keluarga', 'simulated' => true];
    }
}
