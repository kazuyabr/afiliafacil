<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AgentConversation extends Model
{
    protected $table = 'agent_conversations';
    protected $guarded = [];

    public function messages()
    {
        return $this->hasMany(AgentMessage::class, 'conversation_id');
    }
}
