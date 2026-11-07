<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StationCommand extends Model
{
    use HasUuids;

    public const LABELS = ['pending' => 'Menunggu', 'running' => 'Berjalan', 'completed' => 'Selesai', 'rejected' => 'Ditolak', 'failed' => 'Gagal', 'timeout' => 'Timeout'];

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    protected function casts(): array
    {
        return ['parameters' => 'array', 'result' => 'array', 'expires_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'timed_out_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime', 'result_at' => 'immutable_datetime'];
    }

    public function label(): string
    {
        return self::LABELS[$this->status] ?? $this->status;
    }

    public function actionLabel(): string
    {
        return ['feed' => 'Beri pakan', 'water' => 'Salurkan air', 'scenario' => 'Ubah skenario'][$this->action] ?? $this->action;
    }
}
