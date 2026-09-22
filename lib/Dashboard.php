<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Plans.php';
require_once __DIR__ . '/PageManager.php';
require_once __DIR__ . '/Agent/AgentQuota.php';
require_once __DIR__ . '/Agent/AgentProfile.php';
require_once __DIR__ . '/AdSpy/AdSpyQuota.php';
require_once __DIR__ . '/Offers/OfferQuota.php';
require_once __DIR__ . '/Offers/OfferManager.php';

/**
 * "Meu Negócio hoje": valor da plataforma em um olhar — páginas, Sócio,
 * ofertas, criativos, pendências e próximos passos. Tudo por usuário
 * (admin vê os próprios dados + pendências de curadoria).
 */
class Dashboard
{
    public static function summary(int $userId, string $plan, bool $isAdmin): array
    {
        $empty = [
            'socio' => ['conversas' => 0, 'mensagens_mes' => 0, 'pendentes' => 0, 'ultima' => ''],
            'ofertas' => ['aprovadas' => 0, 'top' => [], 'views_restantes' => -1],
            'criativos' => 0,
            'pendencias' => [],
            'proximos' => [],
        ];
        if (!Database::available()) return $empty;

        try {
            $out = $empty;
            $hasAgent = class_exists('AgentQuota');
            $hasAdSpy = class_exists('AdSpyQuota');

            // --- Sócio ---
            $convQuery = \AfiliaFacil\Models\AgentConversation::where('user_id', $userId);
            $out['socio']['conversas'] = (int)(clone $convQuery)->count();
            $out['socio']['ultima'] = (string)(clone $convQuery)->orderByDesc('updated_at')->value('updated_at');
            if ($hasAgent) {
                $q = AgentQuota::check($userId, $plan);
                $out['socio']['mensagens_mes'] = (int)($q['used'] ?? 0);
            }
            $convIds = (clone $convQuery)->pluck('id')->all();
            if (!empty($convIds)) {
                $out['socio']['pendentes'] = (int)\AfiliaFacil\Models\AgentMessage::whereIn('conversation_id', $convIds)
                    ->where('role', 'tool')
                    ->where('status', 'pending_confirmation')
                    ->count();
            }

            // --- Ofertas (clientes nunca veem demos) ---
            $approvedQuery = \AfiliaFacil\Models\Offer::where('status', 'approved');
            if (!$isAdmin) $approvedQuery->where('slug', 'not like', 'demo-%');
            $out['ofertas']['aprovadas'] = (int)(clone $approvedQuery)->count();
            $out['ofertas']['top'] = (clone $approvedQuery)->orderByDesc('score')->orderByDesc('ads_count')
                ->limit(3)->get()->map(fn($o) => [
                    'id' => (int)$o->id,
                    'name' => $o->name,
                    'score' => (int)$o->score,
                    'ads' => (int)$o->ads_count,
                ])->all();
            if (class_exists('OfferQuota')) {
                $oq = OfferQuota::check($userId, $plan);
                $limit = (int)($oq['limit'] ?? 0);
                $out['ofertas']['views_restantes'] = $limit === -1 ? -1 : max(0, $limit - (int)($oq['used'] ?? 0));
            }
            $approvedIds = (clone $approvedQuery)->pluck('id')->all();
            if (!empty($approvedIds)) {
                $out['criativos'] = (int)\AfiliaFacil\Models\OfferCreative::whereIn('offer_id', $approvedIds)->count();
            }

            // --- Pendências ---
            $pend = [];
            if ($out['socio']['pendentes'] > 0) {
                $pend[] = ['icon' => 'fa-hourglass-half', 'color' => '#d97706', 'label' => $out['socio']['pendentes'] . ' ação(ões) do Sócio aguardando sua confirmação', 'url' => '/admin/agent.php'];
            }
            if ($hasAdSpy) {
                $sq = AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH);
                $slimit = (int)($sq['limit'] ?? 0);
                if ($slimit > 0 && (int)($sq['used'] ?? 0) >= $slimit) {
                    $pend[] = ['icon' => 'fa-crosshairs', 'color' => '#dc2626', 'label' => 'Cota de espionagem esgotada — faça upgrade ou use BYOK', 'url' => '/admin/plan.php'];
                }
            }
            if ($isAdmin && class_exists('OfferManager')) {
                $pendingOffers = (int)\AfiliaFacil\Models\Offer::where('status', 'pending')->count();
                if ($pendingOffers > 0) {
                    $pend[] = ['icon' => 'fa-list-check', 'color' => '#7c3aed', 'label' => $pendingOffers . ' oferta(s) aguardando curadoria', 'url' => '/admin/ofertas.php'];
                }
            }
            $out['pendencias'] = $pend;

            $out['proximos'] = self::nextSteps($userId, $plan, $isAdmin, $out);
            return $out;
        } catch (Throwable $e) {
            return $empty;
        }
    }

    private static function nextSteps(int $userId, string $plan, bool $isAdmin, array $out): array
    {
        $steps = [];

        try {
            $pm = new PageManager();
            $pages = $isAdmin ? $pm->list() : $pm->listByUser($userId);
            $totalPages = count($pages);
            $drafts = 0;
            foreach ($pages as $p) {
                if (($p['status'] ?? '') === 'draft') $drafts++;
            }
        } catch (Throwable $e) {
            $totalPages = 0;
            $drafts = 0;
        }

        if ($totalPages === 0) {
            $steps[] = ['title' => 'Clone sua primeira página', 'desc' => 'Cole a URL de uma página que converte e publique em minutos.', 'url' => '/admin/clone.php'];
        }
        if (class_exists('AgentProfile')) {
            $niche = trim((string)(AgentProfile::forUser($userId)['niche'] ?? ''));
            if ($niche === '') {
                $steps[] = ['title' => 'Defina seu nicho com o Sócio', 'desc' => 'O Sócio monta a estratégia a partir do seu nicho e orçamento.', 'url' => '/admin/agent.php'];
            }
        }
        if (class_exists('AdSpyQuota') && Plans::hasFeature($plan, 'adspy')) {
            $sq = AdSpyQuota::check($userId, $plan, AdSpyQuota::KIND_SEARCH);
            if ((int)($sq['used'] ?? 0) === 0) {
                $steps[] = ['title' => 'Espione os concorrentes', 'desc' => 'Veja quem está anunciando no seu nicho agora.', 'url' => '/admin/adspy.php'];
            }
        }
        if (class_exists('OfferQuota') && Plans::hasFeature($plan, 'offers') && $out['ofertas']['views_restantes'] !== 0) {
            $steps[] = ['title' => 'Explore as Ofertas Escalando', 'desc' => 'Escolha uma oferta validada para promover hoje.', 'url' => '/admin/ofertas.php'];
        }
        if ($drafts > 0) {
            $steps[] = ['title' => 'Publique seus rascunhos', 'desc' => $drafts . ' página(s) parada(s) como rascunho.', 'url' => '/admin/pages.php'];
        }

        return array_slice($steps, 0, 3);
    }
}
