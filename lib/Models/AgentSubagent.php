<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AgentSubagent extends Model
{
    protected $table = 'agent_subagents';
    protected $guarded = [];
    protected $casts = [
        'tools' => 'array',
        'active' => 'boolean',
    ];
}
