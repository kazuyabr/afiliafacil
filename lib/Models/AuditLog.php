<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_log';
    protected $guarded = [];
    public $timestamps = false;
}
