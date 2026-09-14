<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class Transcription extends Model
{
    protected $table = 'transcriptions';
    protected $guarded = [];
    protected $casts = [
        'words' => 'array',
        'duration_seconds' => 'integer',
    ];
}
