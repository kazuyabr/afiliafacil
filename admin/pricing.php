<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';

Auth::requireAuth();
if (!Auth::can('manage_pricing')) {
    header('Location: /admin/');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Preços e Planos - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Preços e Planos</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Preços e Planos</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Alterações refletem imediatamente na página de planos e nos checkouts.</p>
                    </div>
                </div>

                <div id="plansContainer">
                    <div class="card"><div class="card-body" style="text-align:center;padding:24px;"><i class="fas fa-spinner fa-spin"></i> Carregando...</div></div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    let cycles = {};

    async function loadPlans() {
        const resp = await fetch('/admin/api/pricing.php?action=list');
        const data = await resp.json();
        if (!data.success) { document.getElementById('plansContainer').innerHTML = '<div class="card"><div class="card-body">' + (data.error || 'Erro') + '</div></div>'; return; }
        cycles = data.cycles;
        renderPlans(data.plans);
    }

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function renderPlans(plans) {
        const container = document.getElementById('plansContainer');
        container.innerHTML = plans.map(p => `
            <div class="card" style="margin-bottom:16px;" data-plan="${p.id}">
                <div class="card-header">
                    <h3><i class="fas fa-tag"></i> ${esc(p.name)} <small style="color:var(--text-secondary);">(${esc(p.id)})</small></h3>
                    <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                        <input type="checkbox" class="plan-active" ${p.active ? 'checked' : ''}> Ativo
                    </label>
                </div>
                <div class="card-body">
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Nome</label>
                            <input type="text" class="form-control plan-name" value="${esc(p.name)}">
                        </div>
                        <div class="form-group">
                            <label>Descrição (label)</label>
                            <input type="text" class="form-control plan-label" value="${esc(p.label)}">
                        </div>
                    </div>
                    <div class="grid-2">
                        <div class="form-group">
                            <label>Máx. páginas (-1 = ilimitado)</label>
                            <input type="number" class="form-control plan-max-pages" value="${p.max_pages}">
                        </div>
                        <div class="form-group">
                            <label>Máx. domínios (-1 = ilimitado)</label>
                            <input type="number" class="form-control plan-max-domains" value="${p.max_domains}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Recursos (separados por vírgula)</label>
                        <input type="text" class="form-control plan-features" value="${esc(p.features.join(', '))}">
                    </div>
                    ${Object.keys(cycles).length ? `
                    <div class="grid-4">
                        ${Object.entries(cycles).map(([cycle, label]) => `
                        <div class="form-group">
                            <label>${esc(label)} (R$)</label>
                            <input type="number" step="0.01" class="form-control plan-price" data-cycle="${cycle}" value="${p.prices[cycle] ?? ''}" placeholder="—">
                        </div>`).join('')}
                    </div>` : ''}
                    <button class="btn btn-primary" onclick="savePlan('${p.id}')"><i class="fas fa-save"></i> Salvar ${esc(p.name)}</button>
                </div>
            </div>
        `).join('');
    }

    async function savePlan(id) {
        const card = document.querySelector(`[data-plan="${id}"]`);
        const body = new URLSearchParams();
        body.append('action', 'update');
        body.append('id', id);
        body.append('name', card.querySelector('.plan-name').value);
        body.append('label', card.querySelector('.plan-label').value);
        body.append('max_pages', card.querySelector('.plan-max-pages').value);
        body.append('max_domains', card.querySelector('.plan-max-domains').value);
        body.append('features', card.querySelector('.plan-features').value);
        body.append('active', card.querySelector('.plan-active').checked ? '1' : '0');
        card.querySelectorAll('.plan-price').forEach(input => {
            if (input.value !== '') body.append('price_' + input.dataset.cycle, input.value);
        });

        const resp = await fetch('/admin/api/pricing.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Plano salvo!', 'success'); loadPlans(); }
        else showToast(data.error || 'Erro ao salvar', 'error');
    }

    loadPlans();
    </script>
</body>
</html>
