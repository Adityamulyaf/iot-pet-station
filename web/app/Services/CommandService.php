<?php

namespace App\Services;

use App\Exceptions\CommandRejected;
use App\Models\Station;
use App\Models\StationCommand;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CommandService
{
    /**
     * @param  'web'|'schedule'  $source
     *
     * @throws CommandRejected when the station cannot accept the command right now
     */
    public function create(User $user, Station $station, string $action, array $parameters = [], string $source = 'web'): StationCommand
    {
        abort_unless(Gate::forUser($user)->allows($action === 'scenario' ? 'manage' : 'operate', $station), 403);

        return DB::transaction(function () use ($user, $station, $action, $parameters, $source): StationCommand {
            $station = Station::query()->lockForUpdate()->findOrFail($station->id);
            [$error, $retryable] = $this->blockingReason($station, $action, $parameters);
            if ($error) {
                throw CommandRejected::because($error, $retryable);
            }
            if ($action !== 'scenario') {
                $parameters = ['duration_ms' => 2000];
            }

            return $station->commands()->create(['user_id' => $user->id, 'action' => $action, 'source' => $source, 'parameters' => $parameters, 'expires_at' => now()->addSeconds(15)]);
        });
    }

    /**
     * Why the station cannot take this command, and whether the reason clears by itself within seconds.
     *
     * @return array{0: ?string, 1: bool}
     */
    private function blockingReason(Station $station, string $action, array $parameters): array
    {
        return match (true) {
            ! in_array($action, ['feed', 'water', 'scenario'], true) => ['Tindakan tidak dikenal.', false],
            ! Cache::get('mqtt-worker-online') => ['Layanan perangkat belum terhubung. Coba kembali sebentar lagi.', true],
            $action !== 'scenario' && $station->commands()->whereIn('status', ['pending', 'running', 'timeout'])->whereNull('resolved_at')->exists() => ['Perintah sebelumnya belum terkonfirmasi. Tunggu hasil perangkat.', true],
            $station->commands()->where('created_at', '>', now()->subSeconds(3))->exists() => ['Tunggu sebentar sebelum mengirim perintah berikutnya.', true],
            $action === 'scenario' => (! $station->simulated || ! app()->environment(['local', 'testing']) || ! in_array($parameters['scenario'] ?? '', ['normal', 'low_water', 'invalid_sensor', 'offline', 'delayed', 'no_result', 'duplicate'], true))
                ? ['Skenario tidak tersedia untuk station ini.', false] : [null, false],
            ! $station->online() || ! $station->hasFreshTelemetry() => ['Station offline atau pembacaan sudah terlambat.', false],
            ! ($station->snapshot['food_valid'] ?? false) || ! ($station->snapshot['water_valid'] ?? false) => ['Sensor tidak valid. Periksa station terlebih dahulu.', false],
            $action === 'water' && ($station->snapshot['water_pct'] ?? 0) >= config('station.bowl_water_full_pct') => ['Wadah air sudah penuh.', false],
            $action === 'feed' && ($station->snapshot['food_g'] ?? 0) >= config('station.bowl_food_full_g') => ['Wadah pakan sudah penuh.', false],
            ($station->actuators['pump'] ?? 'unknown') !== 'off' || ($station->actuators['servo'] ?? 'unknown') !== 'closed' => ['State aktuator belum memastikan kondisi siap.', true],
            $station->commands()->whereIn('action', ['feed', 'water'])->where('status', 'completed')->where('resolved_at', '>', now()->subSeconds(10))->exists() => ['Cooldown aktif. Tunggu 10 detik setelah eksekusi.', true],
            default => [null, false],
        };
    }

    public function expire(): void
    {
        StationCommand::query()->where('status', 'pending')->whereNull('published_at')->where('expires_at', '<=', now())->update(['status' => 'failed', 'resolved_at' => now(), 'reason' => 'Perintah kedaluwarsa sebelum dikirim ke perangkat.']);
        StationCommand::query()->whereIn('status', ['pending', 'running'])->where('created_at', '<=', now()->subSeconds(config('station.timeout_seconds')))->update(['status' => 'timeout', 'timed_out_at' => now(), 'reason' => 'Hasil perangkat belum terkonfirmasi. Kontrol dikunci sampai hasil diterima.']);
    }
}
