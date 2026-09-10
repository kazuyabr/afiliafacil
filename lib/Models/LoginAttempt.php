<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class LoginAttempt extends Model
{
    protected $table = 'login_attempts';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'success' => 'boolean',
    ];
}
