<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationSchedule extends Model
{
    /** ISO day numbers (1 = Senin) mapped to short Indonesian labels. */
    public const DAYS = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

    public const ACTIONS = ['feed' => 'Beri pakan', 'water' => 'Salurkan air'];

    /** A due schedule keeps retrying while the station is busy, for at most this many minutes. */
    public const RETRY_MINUTES = 10;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['days' => 'array', 'enabled' => 'boolean', 'last_run_on' => 'date:Y-m-d'];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /**
     * "Setiap hari", or the selected day labels in week order.
     */
    public function daysLabel(): string
    {
        $days = collect($this->days)->map(fn ($day) => (int) $day)->sort()->values();

        return $days->count() === 7 ? 'Setiap hari' : $days->map(fn (int $day) => self::DAYS[$day] ?? $day)->implode(', ');
    }
}
