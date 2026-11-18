<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    use HasFactory;

    public const ROLE_OWNER = 'owner';

    public const ROLE_OPERATOR = 'operator';

    public const ROLE_VIEWER = 'viewer';

    /** @var array<string, string> */
    public const SHARED_ROLES = [self::ROLE_VIEWER => 'Pemantau', self::ROLE_OPERATOR => 'Operator'];

    protected $guarded = ['id'];

    protected $hidden = ['claim_code_hash'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['simulated' => 'boolean', 'connected' => 'boolean', 'last_seen_at' => 'immutable_datetime', 'telemetry_at' => 'immutable_datetime', 'state_at' => 'immutable_datetime', 'snapshot' => 'array', 'actuators' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Accounts the owner has shared this station with; the pivot role is viewer or operator.
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Stations the user owns or has been given access to.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->where('user_id', $user->id)
            ->orWhereHas('members', fn (Builder $members) => $members->where('users.id', $user->id)));
    }

    /**
     * The user's role on this station, read from the database so revoked access applies immediately.
     */
    public function roleFor(User $user): ?string
    {
        if ($this->user_id !== null && $this->user_id === $user->id) {
            return self::ROLE_OWNER;
        }

        return $this->members()->where('users.id', $user->id)->value('station_user.role');
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
