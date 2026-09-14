<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class OfferSuggestion extends Model
{
    protected $table = 'offer_suggestions';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'payload' => 'array',
    ];
}
