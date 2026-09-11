<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class UserAiConfig extends Model
{
    protected $table = 'user_ai_configs';
    protected $guarded = [];
    protected $hidden = ['api_key_encrypted'];
    protected $casts = [
        'enabled' => 'boolean',
    ];
}
