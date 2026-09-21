<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AiUsageDaily extends Model
{
    protected $table = 'ai_usage_daily';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'requests' => 'integer',
    ];
}
