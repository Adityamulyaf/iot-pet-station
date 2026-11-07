<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceMessage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['payload' => 'array', 'recorded_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }
}
