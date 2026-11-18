<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\StationClaimService;
use Illuminate\Console\Command;

class StationCreate extends Command
{
    protected $signature = 'station:create {device : ID perangkat, huruf/angka/-/_} {name : Nama awal station} {--physical : Perangkat fisik, bukan simulator}';

    protected $description = 'Register an unowned station and print its one-time claim code; broker credentials are handled by deploy/station-create.sh';

    public function handle(StationClaimService $claims): int
    {
        $device = $this->argument('device');
        if (! preg_match('/^[a-zA-Z0-9_-]{1,80}$/', $device)) {
            $this->error('ID perangkat hanya boleh huruf, angka, - dan _.');

            return self::FAILURE;
        }
        if (Station::query()->where('device_id', $device)->exists()) {
            $this->error('Perangkat sudah terdaftar. Pakai station:code untuk membuat kode klaim baru.');

            return self::FAILURE;
        }
        $station = Station::query()->create(['device_id' => $device, 'name' => $this->argument('name'), 'simulated' => ! $this->option('physical')]);
        $code = $claims->issueCode($station);
        $this->info("Station {$device} terdaftar tanpa pemilik.");
        $this->line("Kode klaim: {$code}");

        return self::SUCCESS;
    }
}
