<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class TrainingSample extends Model
{
    protected $table = 'training_samples';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'payload' => 'array',
        'consent' => 'boolean',
        'redacted' => 'boolean',
    ];
}
