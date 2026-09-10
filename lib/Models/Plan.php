<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $table = 'plans';
    protected $keyType = 'string';
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = [
        'features' => 'array',
        'active' => 'boolean',
    ];

    public function prices()
    {
        return $this->hasMany(PlanPrice::class, 'plan_id');
    }

    public function priceFor(string $cycle): ?int
    {
        foreach ($this->prices as $price) {
            if ($price->cycle === $cycle) return (int)$price->amount;
        }
        return null;
    }
}
