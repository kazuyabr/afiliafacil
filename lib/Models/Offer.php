<?php

namespace AfiliaFacil\Models;

use Illuminate\Database\Eloquent\Model;

class Offer extends Model
{
    protected $table = 'offers';
    protected $guarded = [];
    protected $casts = [
        'traffic_sources' => 'array',
        'ai_data' => 'array',
        'score' => 'integer',
        'ads_count' => 'integer',
        'ads_count_prev' => 'integer',
        'scale_pct' => 'integer',
    ];

    public function metrics()
    {
        return $this->hasMany(OfferMetric::class, 'offer_id');
    }

    public function creatives()
    {
        return $this->hasMany(OfferCreative::class, 'offer_id');
    }

    public function pages()
    {
        return $this->hasMany(OfferPage::class, 'offer_id');
    }

    public function suggestions()
    {
        return $this->hasMany(OfferSuggestion::class, 'offer_id');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function sparkline(int $points = 14): array
    {
        $rows = $this->metrics()
            ->orderBy('captured_at', 'desc')
            ->limit($points)
            ->get();

        $values = $rows->pluck('ads_count')->map(fn($v) => (int)$v)->reverse()->values()->all();
        return $values;
    }
}
