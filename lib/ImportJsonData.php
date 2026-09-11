<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Config.php';

class ImportJsonData
{
    public const PERMISSIONS = [
        'manage_users',
        'manage_roles',
        'manage_pricing',
        'manage_pages',
        'manage_settings',
        'manage_payments',
        'manage_storage',
    ];

    public static function run(): void
    {
        if (!Database::available()) return;

        self::seedRoles();
        self::seedPlans();
        self::importUsers();
        self::importSettings();
        self::importPages();
        self::importPayments();
    }

    public static function seedRoles(): void
    {
        $roles = [
            ['name' => 'master', 'label' => 'Master', 'permissions' => self::PERMISSIONS, 'is_system' => true],
            ['name' => 'admin', 'label' => 'Administrador', 'permissions' => self::PERMISSIONS, 'is_system' => true],
            ['name' => 'gerente', 'label' => 'Gerente', 'permissions' => ['manage_pages', 'manage_settings'], 'is_system' => false],
            ['name' => 'afiliado', 'label' => 'Afiliado', 'permissions' => [], 'is_system' => false],
        ];

        foreach ($roles as $role) {
            \AfiliaFacil\Models\Role::firstOrCreate(
                ['name' => $role['name']],
                [
                    'label' => $role['label'],
                    'permissions' => $role['permissions'],
                    'is_system' => $role['is_system'],
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );
        }
    }

    public static function seedPlans(): void
    {
        $plans = [
            [
                'id' => 'trial', 'name' => 'Trial', 'label' => 'Grátis por 3 dias',
                'features' => ['clone'], 'max_pages' => 1, 'max_domains' => 0, 'sort' => 0,
                'max_adspy' => 3, 'max_ai' => 3,
                'prices' => [],
            ],
            [
                'id' => 'vsl', 'name' => 'VSL Start', 'label' => 'Páginas de VSL com delay',
                'features' => ['video', 'delay', 'clone'], 'max_pages' => 1, 'max_domains' => 1, 'sort' => 1,
                'max_adspy' => 0, 'max_ai' => 0,
                'prices' => ['monthly' => 79, 'quarterly' => 159, 'semiannual' => 267, 'annual' => 468],
            ],
            [
                'id' => 'essencial', 'name' => 'Afiliado Pro', 'label' => '5 páginas, 2 domínios',
                'features' => ['clone', 'pressel', 'player', 'pixel', 'cookie', 'backredirect', 'editor', 'adspy'],
                'max_pages' => 5, 'max_domains' => 2, 'sort' => 2,
                'max_adspy' => 30, 'max_ai' => 10,
                'prices' => ['monthly' => 119, 'quarterly' => 237, 'semiannual' => 402, 'annual' => 679],
            ],
            [
                'id' => 'master', 'name' => 'Master Elite', 'label' => 'Tudo ilimitado + integrações',
                'features' => ['clone', 'pressel', 'player', 'pixel', 'cookie', 'backredirect', 'integrations', 'quizz', 'editor', 'adspy'],
                'max_pages' => -1, 'max_domains' => 10, 'sort' => 3,
                'max_adspy' => 300, 'max_ai' => 100,
                'prices' => ['monthly' => 149, 'quarterly' => 297, 'semiannual' => 492, 'annual' => 838],
            ],
            [
                'id' => 'premium', 'name' => 'Admin', 'label' => 'Acesso de administrador',
                'features' => ['clone', 'pressel', 'player', 'pixel', 'cookie', 'backredirect', 'integrations', 'quizz', 'editor', 'adspy'],
                'max_pages' => -1, 'max_domains' => -1, 'sort' => 4,
                'max_adspy' => -1, 'max_ai' => -1,
                'prices' => [],
            ],
        ];

        foreach ($plans as $plan) {
            \AfiliaFacil\Models\Plan::firstOrCreate(
                ['id' => $plan['id']],
                [
                    'name' => $plan['name'],
                    'label' => $plan['label'],
                    'features' => $plan['features'],
                    'max_pages' => $plan['max_pages'],
                    'max_domains' => $plan['max_domains'],
                    'max_adspy_searches' => $plan['max_adspy'],
                    'max_ai_analyses' => $plan['max_ai'],
                    'sort' => $plan['sort'],
                    'active' => true,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]
            );

            foreach ($plan['prices'] as $cycle => $amount) {
                \AfiliaFacil\Models\PlanPrice::firstOrCreate(
                    ['plan_id' => $plan['id'], 'cycle' => $cycle],
                    ['amount' => $amount, 'updated_at' => date('Y-m-d H:i:s')]
                );
            }
        }
    }

    public static function importUsers(): void
    {
        $file = Config::getDataDir() . '/users.json';
        if (!file_exists($file)) return;

        $users = json_decode(file_get_contents($file), true) ?? [];
        $roles = \AfiliaFacil\Models\Role::all()->keyBy('name');

        foreach ($users as $u) {
            if (empty($u['email'])) continue;
            if (\AfiliaFacil\Models\User::where('email', $u['email'])->exists()) continue;

            $roleName = ($u['email'] === 'admin@afiliafacil.com') ? 'master' : 'afiliado';
            $roleId = $roles[$roleName]->id ?? null;

            \AfiliaFacil\Models\User::create([
                'id' => $u['id'] ?? (time() + random_int(1, 9999)),
                'name' => $u['name'] ?? 'Usuário',
                'email' => $u['email'],
                'password' => $u['password'],
                'role_id' => $roleId,
                'plan' => $u['plan'] ?? 'trial',
                'trial_until' => $u['trial_until'] ?? null,
                'active' => true,
                'created_at' => $u['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $u['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        }
    }

    public static function importSettings(): void
    {
        $file = Config::getDataDir() . '/settings.json';
        if (!file_exists($file)) return;

        $settings = json_decode(file_get_contents($file), true) ?? [];
        foreach ($settings as $key => $value) {
            if (\AfiliaFacil\Models\Setting::find($key)) continue;
            \AfiliaFacil\Models\Setting::setValue($key, $value);
        }
    }

    public static function importPages(): void
    {
        $file = Config::getDataDir() . '/pages.json';
        if (!file_exists($file)) return;

        $pages = json_decode(file_get_contents($file), true) ?? [];
        foreach ($pages as $p) {
            if (empty($p['id'])) continue;
            if (\AfiliaFacil\Models\Page::find($p['id'])) continue;

            \AfiliaFacil\Models\Page::create([
                'id' => $p['id'],
                'user_id' => $p['user_id'] ?? 1,
                'name' => $p['name'] ?? 'Sem nome',
                'slug' => $p['slug'] ?? ('page-' . $p['id']),
                'type' => $p['type'] ?? 'landing',
                'status' => $p['status'] ?? 'active',
                'domain' => $p['domain'] ?? '',
                'affiliate_link' => $p['affiliate_link'] ?? '',
                'source_domain' => $p['source_domain'] ?? '',
                'views' => $p['views'] ?? 0,
                'failed_assets' => $p['failed_assets'] ?? [],
                'created_at' => $p['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $p['updated_at'] ?? ($p['created_at'] ?? date('Y-m-d H:i:s')),
            ]);

            if (!empty($p['html'])) {
                $dir = Config::getPagesDir() . '/' . $p['id'];
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                if (!file_exists($dir . '/index.html')) {
                    file_put_contents($dir . '/index.html', $p['html']);
                }
            }
        }
    }

    public static function importPayments(): void
    {
        $file = Config::getDataDir() . '/payments.json';
        if (!file_exists($file)) return;

        $payments = json_decode(file_get_contents($file), true) ?? [];
        foreach ($payments as $p) {
            if (empty($p['id'])) continue;
            if (\AfiliaFacil\Models\Payment::find($p['id'])) continue;

            \AfiliaFacil\Models\Payment::create([
                'id' => $p['id'],
                'user_id' => $p['user_id'] ?? 0,
                'user_email' => $p['user_email'] ?? '',
                'plan_id' => $p['plan_id'] ?? '',
                'cycle' => $p['cycle'] ?? 'monthly',
                'amount' => $p['amount'] ?? 0,
                'gateway' => $p['gateway'] ?? 'pix',
                'status' => $p['status'] ?? 'pending',
                'reference' => $p['reference'] ?? '',
                'payload' => $p['payload'] ?? null,
                'created_at' => $p['created_at'] ?? date('Y-m-d H:i:s'),
                'paid_at' => $p['paid_at'] ?? null,
            ]);
        }
    }
}
