<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['simulated' => 'boolean', 'connected' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'telemetry_at' => 'immutable_datetime', 'state_at' => 'immutable_datetime', 'snapshot' => 'array', 'actuators' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function commands(): HasMany
    {
        return $this->hasMany(StationCommand::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DeviceMessage::class);
    }

    public function online(): bool
    {
        return $this->connected && ($this->last_seen_at?->greaterThan(now()->subSeconds(config('station.offline_seconds'))) ?? false);
    }

    public function hasFreshTelemetry(): bool
    {
        return $this->telemetry_at?->greaterThan(now()->subSeconds(config('station.stale_seconds'))) ?? false;
    }
}
