<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class ModerationEvent extends Model
{
    protected $table = 'moderation_events';
    protected $guarded = [];
    public $timestamps = false;
}
