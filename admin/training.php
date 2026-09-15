<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Training/TrainingCollector.php';

Auth::requireAuth();

if (!Auth::isAdmin()) {
    header('Location: /admin/');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
$export = $_GET['export'] ?? '';

if ($export === 'jsonl' && Database::available()) {
    header('Content-Type: application/x-ndjson; charset=UTF-8');
    header('Content-Disposition: attachment; filename="dataset-treino-' . date('Ymd-His') . '.jsonl"');
    echo TrainingCollector::exportJsonl();
    exit;
}

$stats = TrainingCollector::stats();
$consentedUsers = 0;
if (Database::available()) {
    try {
        $consentedUsers = (int)\AfiliaFacil\Models\User::where('training_consent', true)->count();
        $totalUsers = (int)\AfiliaFacil\Models\User::count();
    } catch (Throwable $e) {
        $totalUsers = 0;
    }
}

$kindLabels = [
    'chat' => 'Conversas (Sócio de IA)',
    'transcription' => 'Transcrições',
    'tts' => 'Narrações (textos)',
    'analysis' => 'Análises de ofertas',
];
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treinamento - AfiliaFacil</title>
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
                <div class="topbar-title">Treinamento</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Dataset de Treinamento</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Amostras consentidas, anonimizadas (PII removida) e redigidas — prontas para fine-tuning (LoRA/AI Search na Cloudflare).</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn btn-primary btn-sm" href="?export=jsonl"><i class="fas fa-file-export"></i> Exportar JSONL</a>
                    </div>
                </div>

                <div class="stats-grid" style="margin-bottom:20px;">
                    <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-database"></i></div><div class="stat-value"><?= $stats['total'] ?></div><div class="stat-label">Amostras no dataset</div></div>
                    <div class="stat-card"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><div class="stat-value"><?= $consentedUsers ?><?= isset($totalUsers) ? '/' . $totalUsers : '' ?></div><div class="stat-label">Usuários com consentimento</div></div>
                    <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-comments"></i></div><div class="stat-value"><?= $stats['by_kind']['chat'] ?? 0 ?></div><div class="stat-label">Conversas</div></div>
                    <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-microphone-lines"></i></div><div class="stat-value"><?= ($stats['by_kind']['transcription'] ?? 0) + ($stats['by_kind']['tts'] ?? 0) ?></div><div class="stat-label">STT + TTS</div></div>
                </div>

                <div class="grid-2">
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-chart-simple"></i> Por tipo</h3></div>
                        <div class="card-body">
                            <?php if (empty($stats['by_kind'])): ?>
                            <p style="color:var(--text-secondary);font-size:.85rem;">Nenhuma amostra ainda. As amostras aparecem quando usuários com consentimento usam a IA.</p>
                            <?php else: ?>
                            <?php foreach ($stats['by_kind'] as $kind => $total): ?>
                            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-color);font-size:.85rem;">
                                <span><?= htmlspecialchars($kindLabels[$kind] ?? $kind) ?></span>
                                <strong><?= (int)$total ?></strong>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-layer-group"></i> Por plano</h3></div>
                        <div class="card-body">
                            <?php if (empty($stats['by_plan'])): ?>
                            <p style="color:var(--text-secondary);font-size:.85rem;">Sem dados ainda.</p>
                            <?php else: ?>
                            <?php foreach ($stats['by_plan'] as $plan => $total): ?>
                            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border-color);font-size:.85rem;">
                                <span><?= htmlspecialchars($plan) ?></span>
                                <strong><?= (int)$total ?></strong>
                            </div>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-top:20px;">
                    <div class="card-header"><h3><i class="fas fa-circle-info"></i> Como usar</h3></div>
                    <div class="card-body" style="font-size:.85rem;line-height:1.7;color:var(--text-secondary);">
                        <p>1. Exporte o JSONL acima (formato: <code>{"kind","plan","payload","created_at"}</code>).</p>
                        <p>2. Para <strong>fine-tuning (LoRA)</strong>: converta para o formato de chat do modelo base e treine (ex.: AutoTrain) — o adapter é enviado à Cloudflare Workers AI (rank ≤8, &lt;300MB).</p>
                        <p>3. Para <strong>base de conhecimento (AI Search/RAG)</strong>: exporte os conteúdos curados em Markdown e indexe na Cloudflare.</p>
                        <p>4. Somente amostras de usuários com consentimento entram no dataset — PII removida e dados de saúde redigidos automaticamente.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
</body>
</html>
