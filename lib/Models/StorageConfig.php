<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class StorageConfig extends Model
{
    protected $table = 'storage_configs';
    protected $guarded = [];
    protected $hidden = ['secret_encrypted'];
    protected $casts = [
        'enabled' => 'boolean',
    ];
}
