<?php
require_once __DIR__ . '/../../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Audit.php';

use AfiliaFacil\Models\Plan;
use AfiliaFacil\Models\PlanPrice;

header('Content-Type: application/json; charset=UTF-8');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

if (!Auth::can('manage_pricing')) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão para gerenciar preços']);
    exit;
}

if (!Database::available()) {
    http_response_code(500);
    echo json_encode(['error' => 'Banco de dados indisponível']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        $plans = Plan::with('prices')->orderBy('sort', 'asc')->get()->map(function ($p) {
            $prices = [];
            foreach ($p->prices as $price) {
                $prices[$price->cycle] = (int)$price->amount;
            }
            return [
                'id' => $p->id,
                'name' => $p->name,
                'label' => $p->label,
                'features' => $p->features ?? [],
                'max_pages' => (int)$p->max_pages,
                'max_domains' => (int)$p->max_domains,
                'max_adspy_searches' => (int)($p->max_adspy_searches ?? 0),
                'max_ai_analyses' => (int)($p->max_ai_analyses ?? 0),
                'active' => (bool)$p->active,
                'prices' => $prices,
            ];
        });
        echo json_encode(['success' => true, 'plans' => $plans, 'cycles' => Plans::CYCLE_LABELS]);
        break;

    case 'update':
        $id = (string)($_POST['id'] ?? '');
        $plan = Plan::find($id);
        if (!$plan) {
            echo json_encode(['error' => 'Plano não encontrado']);
            break;
        }

        if (isset($_POST['name'])) $plan->name = trim($_POST['name']);
        if (isset($_POST['label'])) $plan->label = trim($_POST['label']);
        if (isset($_POST['max_pages'])) $plan->max_pages = (int)$_POST['max_pages'];
        if (isset($_POST['max_domains'])) $plan->max_domains = (int)$_POST['max_domains'];
        if (isset($_POST['max_adspy_searches'])) $plan->max_adspy_searches = (int)$_POST['max_adspy_searches'];
        if (isset($_POST['max_ai_analyses'])) $plan->max_ai_analyses = (int)$_POST['max_ai_analyses'];
        if (isset($_POST['active'])) $plan->active = (bool)$_POST['active'];
        if (isset($_POST['features'])) {
            $features = $_POST['features'];
            if (is_string($features)) $features = array_filter(explode(',', $features));
            $plan->features = array_values($features);
        }
        $plan->updated_at = date('Y-m-d H:i:s');
        $plan->save();

        foreach (Plans::CYCLE_LABELS as $cycle => $label) {
            $key = 'price_' . $cycle;
            if (isset($_POST[$key]) && $_POST[$key] !== '') {
                PlanPrice::updateOrCreate(
                    ['plan_id' => $plan->id, 'cycle' => $cycle],
                    ['amount' => (int)$_POST[$key], 'updated_at' => date('Y-m-d H:i:s')]
                );
            }
        }

        Plans::refresh();
        Audit::log('pricing_updated', 'plan', (string)$plan->id);
        echo json_encode(['success' => true]);
        break;

    default:
        echo json_encode(['error' => 'Ação inválida']);
}
