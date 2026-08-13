<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedLimitRecord extends Model
{
    protected $table = 'v2_speed_limit_record';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
