<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AgentProfile extends Model
{
    protected $table = 'agent_profiles';
    protected $guarded = [];
    protected $casts = [
        'goals' => 'array',
        'preferences' => 'array',
    ];
}
