<?php

require_once __DIR__ . '/Database.php';

class Plans
{
    public const PLANS = [
        'trial' => [
            'name' => 'Trial',
            'price' => 0,
            'features' => ['clone'],
            'max_pages' => 1,
            'max_domains' => 0,
            'label' => 'Grátis por 3 dias',
        ],
        'trial_expired' => [
            'name' => 'Trial Expirado',
            'price' => 0,
            'features' => [],
            'max_pages' => 0,
            'max_domains' => 0,
            'label' => 'Trial expirado - assine para continuar',
        ],
        'vsl' => [
            'name' => 'VSL',
            'price' => 79,
            'cycles' => [
                'monthly' => 79,
                'quarterly' => 159,
                'semiannual' => 267,
                'annual' => 468,
            ],
            'features' => ['video', 'delay', 'clone'],
            'max_pages' => 1,
            'max_domains' => 1,
            'label' => 'Páginas de VSL com delay',
        ],
        'essencial' => [
            'name' => 'Essencial',
            'price' => 119,
            'cycles' => [
                'monthly' => 119,
                'quarterly' => 237,
                'semiannual' => 402,
                'annual' => 679,
            ],
            'features' => ['clone', 'pressel', 'player', 'pixel', 'cookie', 'backredirect', 'editor'],
            'max_pages' => 5,
            'max_domains' => 2,
            'label' => '5 páginas, 2 domínios',
        ],
        'master' => [
            'name' => 'Master',
            'price' => 149,
            'cycles' => [
                'monthly' => 149,
                'quarterly' => 297,
                'semiannual' => 492,
                'annual' => 838,
            ],
            'features' => ['clone', 'pressel', 'player', 'pixel', 'cookie', 'backredirect', 'integrations', 'quizz', 'editor'],
            'max_pages' => -1,
            'max_domains' => 10,
            'label' => 'Tudo ilimitado + integrações',
        ],
        'premium' => [
            'name' => 'Admin',
            'price' => 0,
            'features' => ['clone', 'pressel', 'player', 'pixel', 'cookie', 'backredirect', 'integrations', 'quizz', 'editor'],
            'max_pages' => -1,
            'max_domains' => -1,
            'label' => 'Acesso de administrador',
        ],
    ];

    public const CYCLE_LABELS = [
        'monthly' => 'Mensal',
        'quarterly' => 'Trimestral',
        'semiannual' => 'Semestral',
        'annual' => 'Anual',
    ];

    private static ?array $dbPlans = null;

    private static function loadFromDb(): void
    {
        if (self::$dbPlans !== null) return;
        self::$dbPlans = [];

        if (!Database::available()) return;

        try {
            foreach (\AfiliaFacil\Models\Plan::with('prices')->get() as $plan) {
                $cycles = [];
                foreach ($plan->prices as $price) {
                    $cycles[$price->cycle] = (int)$price->amount;
                }
                $cycles = $cycles ?: [];

                self::$dbPlans[$plan->id] = [
                    'name' => $plan->name,
                    'price' => $cycles['monthly'] ?? 0,
                    'cycles' => $cycles,
                    'features' => $plan->features ?? [],
                    'max_pages' => (int)$plan->max_pages,
                    'max_domains' => (int)$plan->max_domains,
                    'max_adspy_searches' => (int)($plan->max_adspy_searches ?? 0),
                    'max_ai_analyses' => (int)($plan->max_ai_analyses ?? 0),
                    'label' => $plan->label ?? '',
                ];
            }
        } catch (Throwable $e) {
            self::$dbPlans = [];
        }
    }

    public static function all(): array
    {
        self::loadFromDb();
        if (empty(self::$dbPlans)) return self::PLANS;

        $merged = self::$dbPlans;
        foreach (['trial_expired'] as $extra) {
            if (!isset($merged[$extra])) $merged[$extra] = self::PLANS[$extra];
        }
        return $merged;
    }

    public static function get(string $plan): array
    {
        $all = self::all();
        return $all[$plan] ?? $all['trial_expired'] ?? self::PLANS['trial_expired'];
    }

    public static function planName(string $plan): string
    {
        return self::get($plan)['name'];
    }

    public static function cyclePrice(string $plan, string $cycle): int
    {
        $p = self::get($plan);
        return $p['cycles'][$cycle] ?? $p['price'] ?? 0;
    }

    public static function maxPages(string $plan): int
    {
        return self::get($plan)['max_pages'];
    }

    public static function maxAdSpySearches(string $plan): int
    {
        return self::get($plan)['max_adspy_searches'] ?? 0;
    }

    public static function maxAiAnalyses(string $plan): int
    {
        return self::get($plan)['max_ai_analyses'] ?? 0;
    }

    public static function maxDomains(string $plan): int
    {
        return self::get($plan)['max_domains'];
    }

    public static function hasFeature(string $plan, string $feature): bool
    {
        return in_array($feature, self::get($plan)['features'], true);
    }

    public static function pagesRemaining(string $plan, int $currentCount): int
    {
        $max = self::maxPages($plan);
        if ($max === -1) return -1;
        return max(0, $max - $currentCount);
    }

    public static function formatPrice(int $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    public static function refresh(): void
    {
        self::$dbPlans = null;
    }
}
