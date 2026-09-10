<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'payments';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
