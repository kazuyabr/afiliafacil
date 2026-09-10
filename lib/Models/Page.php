<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $table = 'pages';
    protected $guarded = [];
    protected $casts = [
        'failed_assets' => 'array',
    ];
}
