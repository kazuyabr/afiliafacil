<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $table = 'feedback';
    protected $guarded = [];
    protected $casts = [
        'context' => 'array',
    ];
}
