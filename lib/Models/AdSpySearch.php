<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AdSpySearch extends Model
{
    protected $table = 'ad_spy_searches';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'from_cache' => 'boolean',
    ];
}
