<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedLimitRule extends Model
{
    protected $table = 'v2_speed_limit_rule';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];
}
