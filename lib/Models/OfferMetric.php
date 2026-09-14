<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class OfferMetric extends Model
{
    protected $table = 'offer_metrics';
    protected $guarded = [];
    public $timestamps = false;
    protected $casts = [
        'ads_count' => 'integer',
    ];
}
