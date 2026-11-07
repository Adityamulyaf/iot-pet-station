<?php

namespace App\Services;

use App\Models\DeviceMessage;
use App\Models\Station;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MessageIngestor
{
    public function ingest(string $topic, string $json, bool $retained = false): ?array
    {
        if (! preg_match('~^petstation/([a-zA-Z0-9_-]+)/(telemetry|state|result)$~', $topic, $match)) {
            return null;
        }
        $payload = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $kind = $match[2];
        $rules = ['schema_version' => ['required', 'integer', Rule::in([1])], 'device_id' => ['required', Rule::in([$match[1]])], 'message_id' => 'required|uuid', 'recorded_at' => 'required|date|before_or_equal:'.now()->addSeconds(30)->toIso8601String()];
        if ($kind === 'telemetry') {
            $rules += ['food_g' => 'required|numeric|min:0|max:100000', 'water_pct' => 'required|numeric|between:0,100', 'food_raw' => 'required|numeric', 'water_raw' => 'required|numeric', 'food_valid' => 'required|boolean', 'water_valid' => 'required|boolean', 'pir' => 'required|boolean', 'servo' => ['required', Rule::in(['open', 'closed', 'unknown'])], 'pump' => ['required', Rule::in(['on', 'off', 'unknown'])]];
        } elseif ($kind === 'state') {
            $rules += ['online' => 'required|boolean', 'servo' => ['required', Rule::in(['open', 'closed', 'unknown'])], 'pump' => ['required', Rule::in(['on', 'off', 'unknown'])]];
        } else {
            $rules += ['command_id' => 'required|uuid', 'status' => ['required', Rule::in(['running', 'completed', 'rejected', 'failed'])], 'reason' => 'nullable|string|max:500', 'duration_ms' => 'nullable|integer|between:0,3000', 'before' => 'nullable|array', 'after' => 'nullable|array'];
        }
        Validator::make($payload, $rules)->validate();

        return DB::transaction(function () use ($payload, $kind, $retained): array {
            $station = Station::query()->where('device_id', $payload['device_id'])->lockForUpdate()->firstOrFail();
            $message = DeviceMessage::query()->firstOrCreate(['station_id' => $station->id, 'message_id' => $payload['message_id']], ['kind' => $kind, 'payload' => $payload, 'recorded_at' => CarbonImmutable::parse($payload['recorded_at'])->utc(), 'received_at' => now()]);
            if ($message->wasRecentlyCreated) {
                $at = $message->recorded_at;
                if ($kind === 'telemetry' && (! $station->telemetry_at || $at->greaterThan($station->telemetry_at))) {
                    $station->snapshot = $payload;
                    $station->telemetry_at = $at;
                    if (! $station->state_at || $at->greaterThan($station->state_at)) {
                        $station->actuators = ['servo' => $payload['servo'], 'pump' => $payload['pump']];
                        $station->state_at = $at;
                    }
                }
                if ($kind === 'state' && (! $station->state_at || $at->greaterThanOrEqualTo($station->state_at))) {
                    $station->connected = $payload['online'];
                    $station->actuators = ['servo' => $payload['servo'], 'pump' => $payload['pump']];
                    $station->state_at = $at;
                }
                if (! $retained && $at->greaterThan(now()->subSeconds(config('station.offline_seconds'))) && (! $station->last_seen_at || $at->greaterThan($station->last_seen_at))) {
                    $station->last_seen_at = $at;
                    if ($kind === 'telemetry') {
                        $station->connected = true;
                    }
                }
                if ($kind === 'result') {
                    $command = $station->commands()->whereKey($payload['command_id'])->lockForUpdate()->first();
                    if ($command && ! $command->resolved_at && (! $command->result_at || $at->greaterThan($command->result_at))) {
                        $command->result_at = $at;
                        $command->result = $payload;
                        if ($payload['status'] !== 'running') {
                            $command->status = $payload['status'];
                            $command->reason = $payload['reason'] ?? null;
                            $command->resolved_at = now();
                            if ($command->action === 'feed' && $payload['status'] === 'completed' && ($payload['before']['food_valid'] ?? false) && ($payload['after']['food_valid'] ?? false) && isset($payload['before']['food_g'], $payload['after']['food_g']) && $payload['before']['food_g'] - $payload['after']['food_g'] < 0.5) {
                                $command->reason = 'Siklus selesai, perubahan berat belum terdeteksi. Periksa penyaluran.';
                            }
                            if ($command->action === 'scenario' && $payload['status'] === 'completed') {
                                $station->scenario = $command->parameters['scenario'];
                            }
                        } elseif ($command->status !== 'timeout') {
                            $command->status = 'running';
                        }
                        $command->save();
                    }
                }
                $station->save();
            }

            return ['schema_version' => 1, 'device_id' => $station->device_id, 'message_id' => $message->message_id, 'stored_at' => now()->toIso8601String()];
        });
    }
}
