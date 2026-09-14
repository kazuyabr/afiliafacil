<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AgentMessage extends Model
{
    protected $table = 'agent_messages';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'tool_args' => 'array',
        'tool_result' => 'array',
    ];
}
