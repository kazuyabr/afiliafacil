<?php

require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/../Plans.php';

class OfferQuota
{
    public static function used(int $userId): int
    {
        if (!Database::available()) return 0;

        try {
            $start = date('Y-m-01 00:00:00');
            return (int)\AfiliaFacil\Models\OfferView::where('user_id', $userId)
                ->where('created_at', '>=', $start)
                ->distinct()
                ->count('offer_id');
        } catch (Throwable $e) {
            return 0;
        }
    }

    public static function limit(string $plan): int
    {
        return Plans::maxOffersViews($plan);
    }

    public static function alreadyViewed(int $userId, int $offerId): bool
    {
        if (!Database::available()) return false;

        try {
            $start = date('Y-m-01 00:00:00');
            return \AfiliaFacil\Models\OfferView::where('user_id', $userId)
                ->where('offer_id', $offerId)
                ->where('created_at', '>=', $start)
                ->exists();
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function check(int $userId, string $plan, int $offerId = 0): array
    {
        $limit = self::limit($plan);
        $used = self::used($userId);
        $alreadyViewed = $offerId > 0 && self::alreadyViewed($userId, $offerId);

        if ($limit === -1) {
            return ['allowed' => true, 'used' => $used, 'limit' => -1, 'remaining' => -1, 'already_viewed' => $alreadyViewed];
        }

        return [
            'allowed' => $alreadyViewed || $used < $limit,
            'used' => $used,
            'limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'already_viewed' => $alreadyViewed,
        ];
    }

    public static function consume(int $userId, int $offerId): void
    {
        if (!Database::available()) return;
        if (self::alreadyViewed($userId, $offerId)) return;

        try {
            \AfiliaFacil\Models\OfferView::create([
                'user_id' => $userId,
                'offer_id' => $offerId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
        }
    }
}
