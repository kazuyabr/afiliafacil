<?php

require_once __DIR__ . '/../Database.php';

class OfferManager
{
    public const STATUSES = ['pending', 'approved', 'rejected'];
    public const NICHES = ['financas', 'saude', 'emagrecimento', 'relacionamento', 'espiritualidade', 'educacao', 'negocios', 'tecnologia', 'outros'];
    public const STRUCTURES = ['vsl', 'quiz', 'low_ticket', 'infoproduto', 'carta'];
    public const TRAFFIC = ['facebook', 'google', 'tiktok', 'multi'];

    public function list(array $filters = [], int $limit = 60, int $offset = 0): array
    {
        if (!Database::available()) return ['items' => [], 'total' => 0];

        try {
            $query = \AfiliaFacil\Models\Offer::query();

            if (!empty($filters['status']) && in_array($filters['status'], self::STATUSES, true)) {
                $query->where('status', $filters['status']);
            } else {
                $query->where('status', 'approved');
            }
            if (!empty($filters['niche'])) $query->where('niche', $filters['niche']);
            if (!empty($filters['language'])) $query->where('language', $filters['language']);
            if (!empty($filters['structure'])) $query->where('structure', $filters['structure']);
            if (!empty($filters['platform'])) $query->where('platform', $filters['platform']);
            if (!empty($filters['traffic'])) {
                $query->where('traffic_sources', 'like', '%' . $filters['traffic'] . '%');
            }
            if (!empty($filters['q'])) {
                $q = '%' . $filters['q'] . '%';
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'like', $q)
                        ->orWhere('advertiser', 'like', $q)
                        ->orWhere('domain', 'like', $q);
                });
            }

            $total = (clone $query)->count();

            $query->withCount(['creatives', 'pages']);

            $order = $filters['order'] ?? 'score';
            match ($order) {
                'scale' => $query->orderByDesc('scale_pct'),
                'ads' => $query->orderByDesc('ads_count'),
                'recent' => $query->orderByDesc('last_seen_at'),
                default => $query->orderByDesc('score')->orderByDesc('ads_count'),
            };

            $items = $query->limit($limit)->offset($offset)->get();

            $list = $items->map(fn($offer) => $this->toArray($offer))->all();
            $this->attachSparklines($list, $limit);

            return [
                'items' => $list,
                'total' => $total,
            ];
        } catch (Throwable $e) {
            return ['items' => [], 'total' => 0, 'error' => $e->getMessage()];
        }
    }

    public function get(int $id): ?array
    {
        if (!Database::available()) return null;

        try {
            $offer = \AfiliaFacil\Models\Offer::find($id);
            if (!$offer) return null;

            $data = $this->toArray($offer, true);
            $data['creatives'] = $offer->creatives()->orderByDesc('started_at')->limit(60)->get()->map(fn($c) => [
                'id' => (int)$c->id,
                'platform' => $c->platform,
                'advertiser' => $c->advertiser,
                'title' => $c->title,
                'body' => $c->body,
                'cta' => $c->cta,
                'media_type' => $c->media_type,
                'thumbnail_url' => $c->thumbnail_url,
                'media_url' => $c->media_url,
                'landing_page' => $c->landing_page,
                'ad_url' => $c->ad_url,
                'status' => $c->status,
                'started_at' => $c->started_at,
            ])->all();
            $data['pages'] = $offer->pages()->orderBy('type')->get()->map(fn($p) => [
                'id' => (int)$p->id,
                'url' => $p->url,
                'type' => $p->type,
                'title' => $p->title,
                'thumbnail_url' => $p->thumbnail_url,
                'status' => $p->status,
            ])->all();
            return $data;
        } catch (Throwable $e) {
            return null;
        }
    }

    public function stats(): array
    {
        if (!Database::available()) {
            return ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'creatives' => 0, 'pages' => 0];
        }

        try {
            return [
                'pending' => (int)\AfiliaFacil\Models\Offer::where('status', 'pending')->count(),
                'approved' => (int)\AfiliaFacil\Models\Offer::where('status', 'approved')->count(),
                'rejected' => (int)\AfiliaFacil\Models\Offer::where('status', 'rejected')->count(),
                'creatives' => (int)\AfiliaFacil\Models\OfferCreative::count(),
                'pages' => (int)\AfiliaFacil\Models\OfferPage::count(),
            ];
        } catch (Throwable $e) {
            return ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'creatives' => 0, 'pages' => 0];
        }
    }

    public function approve(int $id): bool
    {
        return $this->setStatus($id, 'approved');
    }

    public function reject(int $id): bool
    {
        return $this->setStatus($id, 'rejected');
    }

    public function bulkApprove(array $ids): int
    {
        $count = 0;
        foreach (array_map('intval', $ids) as $id) {
            if ($this->approve($id)) $count++;
        }
        return $count;
    }

    public function bulkReject(array $ids): int
    {
        $count = 0;
        foreach (array_map('intval', $ids) as $id) {
            if ($this->reject($id)) $count++;
        }
        return $count;
    }

    public function setStatus(int $id, string $status): bool
    {
        if (!Database::available()) return false;
        if (!in_array($status, self::STATUSES, true)) return false;

        try {
            $offer = \AfiliaFacil\Models\Offer::find($id);
            if (!$offer) return false;

            $offer->status = $status;
            $offer->approved_at = $status === 'approved' ? date('Y-m-d H:i:s') : null;
            $offer->updated_at = date('Y-m-d H:i:s');
            $offer->save();

            if (class_exists('Audit')) Audit::log('offer_' . $status, 'offer', (string)$id, ['name' => $offer->name]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function upsert(array $data, array $ads = []): array
    {
        if (!Database::available()) return ['offer' => null, 'created' => false];

        try {
            $slug = $this->makeSlug($data['slug'] ?? $data['domain'] ?? $data['advertiser'] ?? $data['name'] ?? '');
            $existing = \AfiliaFacil\Models\Offer::where('slug', $slug)->first();

            $adsCount = count($ads);
            $now = date('Y-m-d H:i:s');

            if ($existing) {
                $prev = (int)$existing->ads_count;
                $existing->ads_count_prev = $prev;
                $existing->ads_count = max($adsCount, $prev);
                $existing->scale_pct = $prev > 0 ? (int)round((($existing->ads_count - $prev) / $prev) * 100) : 0;
                $existing->last_seen_at = $now;
                foreach (['advertiser', 'domain', 'source_url', 'platform', 'thumbnail_url'] as $field) {
                    if (!empty($data[$field])) $existing->$field = $data[$field];
                }
                if (!empty($data['traffic_sources'])) {
                    $existing->traffic_sources = array_values(array_unique(array_merge(
                        $existing->traffic_sources ?? [],
                        $data['traffic_sources']
                    )));
                }
                $existing->updated_at = $now;
                $existing->save();
                $offer = $existing;
                $created = false;
            } else {
                $offer = \AfiliaFacil\Models\Offer::create([
                    'slug' => $slug,
                    'name' => mb_substr($data['name'] ?? $data['advertiser'] ?? $data['domain'] ?? 'Oferta', 0, 190),
                    'advertiser' => mb_substr($data['advertiser'] ?? '', 0, 190),
                    'domain' => mb_substr($data['domain'] ?? '', 0, 190),
                    'source_url' => mb_substr($data['source_url'] ?? '', 0, 500),
                    'niche' => $data['niche'] ?? '',
                    'language' => $data['language'] ?? 'pt',
                    'structure' => $data['structure'] ?? '',
                    'traffic_sources' => $data['traffic_sources'] ?? [],
                    'platform' => $data['platform'] ?? 'meta',
                    'status' => 'pending',
                    'score' => 0,
                    'thumbnail_url' => mb_substr($data['thumbnail_url'] ?? '', 0, 500),
                    'ads_count' => $adsCount,
                    'ads_count_prev' => 0,
                    'scale_pct' => 0,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created = true;
            }

            $this->recordMetric((int)$offer->id, (int)$offer->ads_count);
            $this->storeCreatives((int)$offer->id, $ads);
            $this->storePages((int)$offer->id, $ads);

            return ['offer' => $offer, 'created' => $created];
        } catch (Throwable $e) {
            return ['offer' => null, 'created' => false, 'error' => $e->getMessage()];
        }
    }

    private function attachSparklines(array &$items, int $points = 14): void
    {
        $ids = array_column($items, 'id');
        if (empty($ids)) return;

        try {
            $rows = \AfiliaFacil\Models\OfferMetric::whereIn('offer_id', $ids)
                ->orderBy('captured_at')
                ->get()
                ->groupBy('offer_id');

            foreach ($items as &$item) {
                $group = $rows->get($item['id']) ?? collect();
                $values = $group->pluck('ads_count')->map(fn($v) => (int)$v)->all();
                $item['sparkline'] = array_slice($values, -$points);
            }
            unset($item);
        } catch (Throwable $e) {
        }
    }

    public function recordMetric(int $offerId, int $adsCount): void
    {        try {
            $last = \AfiliaFacil\Models\OfferMetric::where('offer_id', $offerId)
                ->orderByDesc('captured_at')
                ->first();

            $today = date('Y-m-d');
            if ($last && substr((string)$last->captured_at, 0, 10) === $today) {
                $last->ads_count = $adsCount;
                $last->save();
                return;
            }

            \AfiliaFacil\Models\OfferMetric::create([
                'offer_id' => $offerId,
                'ads_count' => $adsCount,
                'captured_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }

    public function storeCreatives(int $offerId, array $ads): int
    {
        $stored = 0;
        foreach (array_slice($ads, 0, 80) as $ad) {
            try {
                $adId = (string)($ad['id'] ?? '');
                $existing = $adId !== ''
                    ? \AfiliaFacil\Models\OfferCreative::where('offer_id', $offerId)->where('ad_id', $adId)->first()
                    : null;

                $payload = [
                    'platform' => $ad['provider'] ?? ($ad['platform'] ?? 'meta'),
                    'ad_id' => mb_substr($adId, 0, 120),
                    'advertiser' => mb_substr($ad['advertiser'] ?? '', 0, 190),
                    'title' => mb_substr($ad['title'] ?? '', 0, 255),
                    'body' => $ad['text'] ?? ($ad['body'] ?? null),
                    'cta' => mb_substr($ad['cta'] ?? '', 0, 80),
                    'media_type' => $ad['media_type'] ?? 'image',
                    'thumbnail_url' => mb_substr($ad['thumbnail'] ?? ($ad['media_url'] ?? ''), 0, 500),
                    'media_url' => mb_substr($ad['media_url'] ?? '', 0, 500),
                    'landing_page' => mb_substr($ad['landing_page'] ?? '', 0, 500),
                    'ad_url' => mb_substr($ad['link'] ?? '', 0, 500),
                    'status' => $ad['status'] ?? 'active',
                    'started_at' => $ad['started_at'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                ];

                if ($existing) {
                    $existing->fill($payload)->save();
                } else {
                    $payload['offer_id'] = $offerId;
                    \AfiliaFacil\Models\OfferCreative::create($payload);
                }
                $stored++;
            } catch (Throwable $e) {
            }
        }
        return $stored;
    }

    public function storePages(int $offerId, array $ads): int
    {
        $seen = [];
        $stored = 0;

        foreach ($ads as $ad) {
            $url = trim($ad['landing_page'] ?? '');
            if ($url === '') continue;

            $type = $this->classifyPage($url);
            $key = $type . '|' . $url;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;

            try {
                $existing = \AfiliaFacil\Models\OfferPage::where('offer_id', $offerId)->where('url', $url)->first();
                if ($existing) continue;

                \AfiliaFacil\Models\OfferPage::create([
                    'offer_id' => $offerId,
                    'url' => mb_substr($url, 0, 500),
                    'type' => $type,
                    'title' => mb_substr($ad['title'] ?? '', 0, 190),
                    'thumbnail_url' => mb_substr($ad['thumbnail'] ?? '', 0, 500),
                    'status' => 'active',
                    'created_at' => date('Y-m-d H:i:s'),
                ]);
                $stored++;
            } catch (Throwable $e) {
            }
        }
        return $stored;
    }

    public function classifyPage(string $url): string
    {
        $lower = mb_strtolower($url);
        if (preg_match('#(hotmart|kiwify|eduzz|braip|monetizze|cartpanda|payt|ticto|perfectpay|lastlink|nutror|vendd|checkout|pay)#i', $lower)) {
            return 'checkout';
        }
        if (str_contains($lower, 'facebook.com') || str_contains($lower, 'fb.com')) return 'fb_page';
        if (str_contains($lower, 'youtube.com') || str_contains($lower, 'youtu.be') || str_contains($lower, 'vimeo.com')) return 'vsl';
        return 'main';
    }

    public function distinctValues(string $column): array
    {
        if (!Database::available()) return [];
        if (!in_array($column, ['niche', 'language', 'structure', 'platform'], true)) return [];

        try {
            return \AfiliaFacil\Models\Offer::where('status', 'approved')
                ->whereNotNull($column)
                ->where($column, '!=', '')
                ->distinct()
                ->orderBy($column)
                ->pluck($column)
                ->all();
        } catch (Throwable $e) {
            return [];
        }
    }

    public function toArray($offer, bool $withSparkline = false): array
    {
        $data = [
            'id' => (int)$offer->id,
            'slug' => $offer->slug,
            'name' => $offer->name,
            'advertiser' => $offer->advertiser,
            'domain' => $offer->domain,
            'source_url' => $offer->source_url,
            'niche' => $offer->niche,
            'language' => $offer->language,
            'structure' => $offer->structure,
            'traffic_sources' => $offer->traffic_sources ?? [],
            'platform' => $offer->platform,
            'status' => $offer->status,
            'score' => (int)$offer->score,
            'ai_summary' => $offer->ai_summary,
            'ai_data' => $offer->ai_data,
            'thumbnail_url' => $offer->thumbnail_url,
            'ads_count' => (int)$offer->ads_count,
            'ads_count_prev' => (int)$offer->ads_count_prev,
            'scale_pct' => (int)$offer->scale_pct,
            'first_seen_at' => (string)$offer->first_seen_at,
            'last_seen_at' => (string)$offer->last_seen_at,
            'creatives_count' => (int)($offer->creatives_count ?? 0),
            'pages_count' => (int)($offer->pages_count ?? 0),
        ];

        if ($withSparkline) {
            $data['sparkline'] = $offer->sparkline();
        }
        return $data;
    }

    private function makeSlug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/^https?:\/\//', '', $value);
        $value = preg_replace('/^www\./', '', $value);
        $slug = preg_replace('/[^a-z0-9]+/', '-', $value);
        $slug = trim($slug ?? '', '-');
        if ($slug === '') $slug = 'oferta';
        return mb_substr($slug, 0, 90) . '-' . substr(hash('sha256', $value), 0, 6);
    }
}
