<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class TtsGeneration extends Model
{
    protected $table = 'tts_generations';
    protected $guarded = [];
    protected $casts = [
        'chars' => 'integer',
    ];
}
