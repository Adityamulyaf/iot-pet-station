<?php

namespace App\Services;

use App\Models\Station;
use App\Models\StationCommand;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommandService
{
    public function create(User $user, Station $station, string $action, array $parameters = []): StationCommand
    {
        abort_unless($station->user_id === $user->id, 403);

        return DB::transaction(function () use ($user, $station, $action, $parameters): StationCommand {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);
            $error = null;
            if (! in_array($action, ['feed', 'water', 'scenario'], true)) {
                $error = 'Tindakan tidak dikenal.';
            } elseif (! Cache::get('mqtt-worker-online')) {
                $error = 'Layanan perangkat belum terhubung. Coba kembali sebentar lagi.';
            } elseif ($action !== 'scenario' && $station->commands()->whereIn('status', ['pending', 'running', 'timeout'])->whereNull('resolved_at')->exists()) {
                $error = 'Perintah sebelumnya belum terkonfirmasi. Tunggu hasil perangkat.';
            } elseif ($station->commands()->where('created_at', '>', now()->subSeconds(3))->exists()) {
                $error = 'Tunggu sebentar sebelum mengirim perintah berikutnya.';
            } elseif ($action === 'scenario') {
                if (! $station->simulated || ! app()->environment(['local', 'testing']) || ! in_array($parameters['scenario'] ?? '', ['normal', 'low_water', 'invalid_sensor', 'offline', 'delayed', 'no_result', 'duplicate'], true)) {
                    $error = 'Skenario tidak tersedia untuk station ini.';
                }
            } elseif (! $station->online() || ! $station->hasFreshTelemetry()) {
                $error = 'Station offline atau pembacaan sudah terlambat.';
            } elseif (! ($station->snapshot['food_valid'] ?? false) || ! ($station->snapshot['water_valid'] ?? false)) {
                $error = 'Sensor tidak valid. Periksa station terlebih dahulu.';
            } elseif ($action === 'water' && ($station->snapshot['water_pct'] ?? 0) < 20) {
                $error = 'Level air reservoir terlalu rendah.';
            } elseif ($action === 'feed' && ($station->snapshot['food_g'] ?? 0) < 30) {
                $error = 'Persediaan pakan terlalu rendah.';
            } elseif (($station->actuators['pump'] ?? 'unknown') !== 'off' || ($station->actuators['servo'] ?? 'unknown') !== 'closed') {
                $error = 'State aktuator belum memastikan kondisi siap.';
            } elseif ($station->commands()->whereIn('action', ['feed', 'water'])->where('status', 'completed')->where('resolved_at', '>', now()->subSeconds(10))->exists()) {
                $error = 'Cooldown aktif. Tunggu 10 detik setelah eksekusi.';
            }
            if ($error) {
                throw ValidationException::withMessages(['command' => $error]);
            }
            if ($action !== 'scenario') {
                $parameters = ['duration_ms' => 2000];
            }

            return $station->commands()->create(['user_id' => $user->id, 'action' => $action, 'parameters' => $parameters, 'expires_at' => now()->addSeconds(15)]);
        });
    }

    public function expire(): void
    {
        StationCommand::query()->where('status', 'pending')->whereNull('published_at')->where('expires_at', '<=', now())->update(['status' => 'failed', 'resolved_at' => now(), 'reason' => 'Perintah kedaluwarsa sebelum dikirim ke perangkat.']);
        StationCommand::query()->whereIn('status', ['pending', 'running'])->where('created_at', '<=', now()->subSeconds(config('station.timeout_seconds')))->update(['status' => 'timeout', 'timed_out_at' => now(), 'reason' => 'Hasil perangkat belum terkonfirmasi. Kontrol dikunci sampai hasil diterima.']);
    }
}
