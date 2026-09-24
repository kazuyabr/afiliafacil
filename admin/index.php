<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/PageManager.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Dashboard.php';

Auth::requireAuth();

$pm = new PageManager();
$user = Auth::user();
$stats = Auth::isAdmin() ? $pm->getStats() : $pm->getStatsByUser((int)$user['id']);
$dash = Dashboard::summary((int)$user['id'], $user['plan'], Auth::isAdmin());
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - AfiliaFacil</title>
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
                <div class="topbar-title">Dashboard</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()" title="Alternar tema">
                        <i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i>
                    </button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Olá, <?= htmlspecialchars($user['name']) ?> 👋</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;">Meu Negócio hoje — o que importa, em um olhar</p>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
                        <div class="stat-value"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total de Páginas</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                        <div class="stat-value"><?= $stats['active'] ?></div>
                        <div class="stat-label">Ativas</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon orange"><i class="fas fa-eye"></i></div>
                        <div class="stat-value"><?= number_format($stats['total_views']) ?></div>
                        <div class="stat-label">Total de Views</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="fas fa-clone"></i></div>
                        <div class="stat-value"><?= $stats['draft'] ?></div>
                        <div class="stat-label">Rascunhos</div>
                    </div>
                </div>

                <div class="grid-2" style="margin-bottom:24px;">
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-triangle-exclamation" style="color:var(--warning);"></i> Pendências</h3></div>
                        <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
                            <?php if (empty($dash['pendencias'])): ?>
                            <div style="display:flex;align-items:center;gap:10px;padding:12px;color:var(--text-secondary);font-size:.88rem;">
                                <i class="fas fa-check-circle" style="color:var(--success);font-size:1.2rem;"></i> Tudo em dia — nada precisando de você agora.
                            </div>
                            <?php else: ?>
                            <?php foreach ($dash['pendencias'] as $pend): ?>
                            <a href="<?= htmlspecialchars($pend['url']) ?>" style="display:flex;align-items:center;gap:12px;padding:12px;border-radius:var(--radius);border:1px solid var(--border-color);text-decoration:none;color:var(--text-primary);">
                                <i class="fas <?= htmlspecialchars($pend['icon']) ?>" style="color:<?= htmlspecialchars($pend['color']) ?>;font-size:1.1rem;"></i>
                                <span style="font-size:.88rem;"><?= htmlspecialchars($pend['label']) ?></span>
                            </a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-list-check" style="color:var(--accent);"></i> Próximos passos</h3></div>
                        <div class="card-body" style="display:flex;flex-direction:column;gap:8px;">
                            <?php $stepNum = 0; ?>
                            <?php foreach ($dash['proximos'] as $step): $stepNum++; ?>
                            <a href="<?= htmlspecialchars($step['url']) ?>" style="display:flex;gap:12px;padding:12px;border-radius:var(--radius);border:1px solid var(--border-color);text-decoration:none;color:var(--text-primary);">
                                <div style="min-width:26px;height:26px;border-radius:50%;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.8rem;"><?= $stepNum ?></div>
                                <div><strong style="font-size:.88rem;"><?= htmlspecialchars($step['title']) ?></strong><br><span style="font-size:.8rem;color:var(--text-secondary);"><?= htmlspecialchars($step['desc']) ?></span></div>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="grid-2" style="margin-bottom:24px;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-handshake"></i> Sócio de IA</h3>
                            <a href="/admin/agent.php" class="btn btn-sm btn-outline">Abrir</a>
                        </div>
                        <div class="card-body">
                            <div style="display:flex;gap:18px;flex-wrap:wrap;font-size:.85rem;">
                                <div><strong style="font-size:1.2rem;"><?= (int)$dash['socio']['conversas'] ?></strong><br><span style="color:var(--text-secondary);">conversas</span></div>
                                <div><strong style="font-size:1.2rem;"><?= (int)$dash['socio']['mensagens_mes'] ?></strong><br><span style="color:var(--text-secondary);">msgs no mês</span></div>
                                <div><strong style="font-size:1.2rem;<?= $dash['socio']['pendentes'] > 0 ? 'color:var(--warning);' : '' ?>"><?= (int)$dash['socio']['pendentes'] ?></strong><br><span style="color:var(--text-secondary);">aguardando você</span></div>
                            </div>
                            <?php if ($dash['socio']['ultima'] !== ''): ?>
                            <p style="font-size:.75rem;color:var(--text-secondary);margin:10px 0 0;">Última atividade: <?= date('d/m H:i', strtotime($dash['socio']['ultima'])) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-fire"></i> Ofertas Escalando</h3>
                            <a href="/admin/ofertas.php" class="btn btn-sm btn-outline">Ver todas</a>
                        </div>
                        <div class="card-body">
                            <p style="font-size:.85rem;margin:0 0 10px;"><strong style="font-size:1.2rem;"><?= (int)$dash['ofertas']['aprovadas'] ?></strong> <span style="color:var(--text-secondary);">validadas · <?= (int)$dash['criativos'] ?> criativos</span></p>
                            <?php if (empty($dash['ofertas']['top'])): ?>
                            <p style="font-size:.82rem;color:var(--text-secondary);">Nenhuma oferta validada ainda.</p>
                            <?php else: ?>
                            <?php foreach ($dash['ofertas']['top'] as $top): ?>
                            <a href="/admin/ofertas.php" style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px dashed var(--border-color);text-decoration:none;color:var(--text-primary);font-size:.85rem;">
                                <span><?= htmlspecialchars(mb_substr($top['name'], 0, 42)) ?></span>
                                <span style="color:var(--text-secondary);white-space:nowrap;">★ <?= (int)$top['score'] ?> · <?= (int)$top['ads'] ?> ads</span>
                            </a>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-rocket"></i> Ações Rápidas</h3>
                    </div>
                    <div class="card-body">
                        <div class="grid-4" style="gap:12px;">
                            <?php if (Plans::hasFeature($user['plan'], 'agent') || Auth::isAdmin()): ?>
                            <a href="/admin/agent.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#e0f2fe;color:#0369a1;"><i class="fas fa-handshake"></i></div>
                                <h3>Fale com seu Sócio de IA</h3>
                                <p>Seu parceiro de tráfego: pergunta, orienta e protege</p>
                            </a>
                            <?php endif; ?>
                            <a href="/admin/clone.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#e7f1ff;color:#0d6efd;"><i class="fas fa-clone"></i></div>
                                <h3>Clonar Página</h3>
                                <p>Cole uma URL e gere uma cópia completa</p>
                            </a>
                            <a href="/admin/pages.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#d1e7dd;color:#198754;"><i class="fas fa-plus-circle"></i></div>
                                <h3>Criar Página</h3>
                                <p>Use templates prontos ou crie do zero</p>
                            </a>
                            <a href="/admin/pressel.php" class="feature-card" style="text-decoration:none;">
                                <div class="feature-icon" style="background:#e8daff;color:#6f42c1;"><i class="fas fa-steam"></i></div>
                                <h3>Pressel</h3>
                                <p>Gere pressels prontas em segundos</p>
                            </a>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-clock"></i> Páginas Recentes</h3>
                        <a href="/admin/pages.php" class="btn btn-sm btn-outline">Ver todas</a>
                    </div>
                        <div class="card-body">
                            <?php
                            $pages = Auth::isAdmin() ? $pm->list() : $pm->listByUser((int)$user['id']);
                            $recent = array_slice(array_reverse($pages), 0, 5);
                            if (empty($recent)):
                            ?>
                            <div class="empty-state">
                                <i class="fas fa-file-plus"></i>
                                <h3>Nenhuma página ainda</h3>
                                <p>Comece clonando uma página ou criando uma nova.</p>
                                <a href="/admin/clone.php" class="btn btn-primary btn-sm"><i class="fas fa-clone"></i> Clonar agora</a>
                            </div>
                            <?php else: ?>
                            <div class="table-wrapper">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Nome</th>
                                            <th>Tipo</th>
                                            <th>Status</th>
                                            <th>Views</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent as $p): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                                            <td><span style="font-size:.8rem;color:var(--text-secondary);"><?= $p['type'] ?></span></td>
                                            <td><span class="status status-<?= $p['status'] ?>"><?= $p['status'] ?></span></td>
                                            <td><?= number_format($p['views']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
