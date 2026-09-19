<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AgentJob extends Model
{
    protected $table = 'agent_jobs';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'attempts' => 'integer',
    ];
}
