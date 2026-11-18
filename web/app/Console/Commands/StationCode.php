<?php

namespace App\Console\Commands;

use App\Models\Station;
use App\Services\StationClaimService;
use Illuminate\Console\Command;

class StationCode extends Command
{
    protected $signature = 'station:code {device : ID perangkat}';

    protected $description = 'Issue a new one-time claim code for a station that has no owner';

    public function handle(StationClaimService $claims): int
    {
        $station = Station::query()->where('device_id', $this->argument('device'))->first();
        if (! $station) {
            $this->error('Perangkat tidak ditemukan.');

            return self::FAILURE;
        }
        if ($station->user_id !== null) {
            $this->error('Station sudah punya pemilik. Pemilik perlu melepasnya dulu dari halaman Pengaturan.');

            return self::FAILURE;
        }
        $this->line('Kode klaim: '.$claims->issueCode($station));

        return self::SUCCESS;
    }
}
