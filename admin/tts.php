<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Ai/TtsConfig.php';
require_once Config::getLibDir() . '/Ai/TtsQuota.php';

Auth::requireAuth();

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$theme = $_SESSION['theme'] ?? 'light';
$quota = TtsQuota::check((int)$user['id'], $user['plan']);
$available = TtsConfig::isAvailable((int)$user['id']);
$config = TtsConfig::forUser((int)$user['id']);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Narração - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .tts-textarea { width:100%; min-height:180px; resize:vertical; font-family:inherit; font-size:.9rem; line-height:1.6; }
        .tts-item { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 14px; border-bottom:1px solid var(--border-color); }
        .tts-item:last-child { border-bottom:none; }
        .tts-item .meta { font-size:.72rem; color:var(--text-secondary); margin-top:2px; }
        .char-counter { font-size:.75rem; color:var(--text-secondary); text-align:right; margin-top:4px; }
        .char-counter.over { color:var(--danger); font-weight:600; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Narração</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Narração (TTS)</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Transforme roteiros e textos em narração — ideal para VSLs, anúncios e vídeos.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="quota-pill" id="quotaPill"><i class="fas fa-volume-high"></i> Narrações: <strong><?= $quota['limit'] === -1 ? 'ilimitado' : $quota['used'] . '/' . $quota['limit'] ?></strong></span>
                        <span class="quota-pill" title="Provider ativo"><i class="fas fa-robot"></i> <strong><?= htmlspecialchars($config['provider']) ?></strong> <?= $config['source'] === 'byok' ? '(BYOK)' : '(plataforma)' ?></span>
                    </div>
                </div>

                <?php if (!$available): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> Narração não configurada. Configure o Cloudflare Workers AI da plataforma ou sua própria chave em <a href="/admin/ai-settings.php"><strong>IA (BYOK)</strong></a>.
                </div>
                <?php endif; ?>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header">
                        <h3><i class="fas fa-volume-high"></i> Novo áudio</h3>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <select id="ttsTranscript" class="form-control" style="width:auto;font-size:.8rem;" onchange="loadTranscriptText()">
                                <option value="">Carregar texto de uma transcrição...</option>
                            </select>
                            <select id="ttsVoice" class="form-control" style="width:auto;font-size:.8rem;"></select>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>Texto para narrar</label>
                            <textarea id="ttsText" class="form-control tts-textarea" placeholder="Cole aqui o roteiro da sua VSL, anúncio ou vídeo..." oninput="updateCounter()"></textarea>
                            <div class="char-counter" id="ttsCounter">0 / 5000</div>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button class="btn btn-primary" id="ttsBtn" onclick="runTts()"><i class="fas fa-play"></i> Gerar narração</button>
                            <button class="btn btn-outline" onclick="document.getElementById('ttsText').value=''; updateCounter();"><i class="fas fa-eraser"></i> Limpar</button>
                        </div>
                    </div>
                </div>

                <div id="ttsLoading" style="display:none;">
                    <div class="card" style="margin-bottom:24px;"><div class="card-body" style="text-align:center;padding:30px;">
                        <i class="fas fa-spinner fa-spin" style="font-size:1.8rem;color:var(--accent);"></i>
                        <p style="margin-top:10px;color:var(--text-secondary);">Gerando narração... textos longos podem levar até 1 minuto.</p>
                    </div></div>
                </div>

                <div id="ttsResult" style="display:none;margin-bottom:24px;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-circle-play"></i> Narração gerada</h3>
                            <a class="btn btn-outline btn-sm" id="ttsDownload" href="#" download><i class="fas fa-download"></i> Baixar áudio</a>
                        </div>
                        <div class="card-body">
                            <div id="ttsMeta" style="font-size:.75rem;color:var(--text-secondary);margin-bottom:10px;"></div>
                            <audio id="ttsPlayer" controls style="width:100%;"></audio>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-clock-rotate-left"></i> Histórico</h3></div>
                    <div class="card-body" id="ttsHistory" style="padding:0;">
                        <div style="text-align:center;color:var(--text-secondary);padding:24px;">Carregando...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    const MAX_CHARS = 5000;
    let voicesLoaded = false;

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function updateCounter() {
        const len = document.getElementById('ttsText').value.length;
        const el = document.getElementById('ttsCounter');
        el.textContent = len + ' / ' + MAX_CHARS;
        el.classList.toggle('over', len > MAX_CHARS);
    }

    function updateQuota(quota) {
        if (!quota) return;
        document.getElementById('quotaPill').innerHTML = '<i class="fas fa-volume-high"></i> Narrações: <strong>' + (quota.limit === -1 ? 'ilimitado' : quota.used + '/' + quota.limit) + '</strong>';
    }

    async function loadConfig() {
        const resp = await fetch('/admin/api/tts.php?action=quota');
        const data = await resp.json();
        if (!data.success) return;
        updateQuota(data.quota);

        const voiceSelect = document.getElementById('ttsVoice');
        voiceSelect.innerHTML = '';
        (data.voices || []).forEach(([value, label]) => {
            const opt = new Option(label, value);
            if (value === data.default_voice) opt.selected = true;
            voiceSelect.appendChild(opt);
        });
        voicesLoaded = true;
    }

    async function loadTranscriptOptions() {
        const resp = await fetch('/admin/api/transcribe.php?action=list');
        const data = await resp.json();
        const select = document.getElementById('ttsTranscript');
        (data.items || []).filter(t => t.status === 'completed').forEach(t => {
            const opt = new Option(t.source_url.substring(0, 70), t.id);
            select.appendChild(opt);
        });
    }

    async function loadTranscriptText() {
        const id = document.getElementById('ttsTranscript').value;
        if (!id) return;
        const resp = await fetch('/admin/api/transcribe.php?action=get&id=' + id);
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        document.getElementById('ttsText').value = data.transcription.text || '';
        updateCounter();
        showToast('Texto da transcrição carregado', 'success');
    }

    async function runTts() {
        const text = document.getElementById('ttsText').value.trim();
        if (!text) { showToast('Informe o texto para narrar', 'warning'); return; }
        if (text.length > MAX_CHARS) { showToast('Texto acima de ' + MAX_CHARS + ' caracteres', 'warning'); return; }

        const body = new URLSearchParams();
        body.append('action', 'generate');
        body.append('text', text);
        body.append('voice', document.getElementById('ttsVoice').value);

        document.getElementById('ttsLoading').style.display = 'block';
        document.getElementById('ttsResult').style.display = 'none';

        try {
            const resp = await fetch('/admin/api/tts.php', { method: 'POST', body });
            const data = await resp.json();
            if (data.error) { showToast(data.error, 'error'); return; }
            showResult(data);
            updateQuota(data.quota);
            loadHistory();
        } catch (err) {
            showToast('Erro de conexão: ' + err.message, 'error');
        } finally {
            document.getElementById('ttsLoading').style.display = 'none';
        }
    }

    function showResult(data) {
        const url = '/admin/api/tts.php?action=audio&id=' + data.id;
        const player = document.getElementById('ttsPlayer');
        player.src = url;
        document.getElementById('ttsDownload').href = url;
        document.getElementById('ttsMeta').textContent = (data.provider || '') + ' · ' + (data.voice || '') + ' · ' + (data.format || '').toUpperCase();
        document.getElementById('ttsResult').style.display = 'block';
        player.play().catch(() => {});
    }

    async function loadHistory() {
        const resp = await fetch('/admin/api/tts.php?action=list');
        const data = await resp.json();
        const container = document.getElementById('ttsHistory');
        const items = data.items || [];
        if (!items.length) {
            container.innerHTML = '<div style="text-align:center;color:var(--text-secondary);padding:24px;">Nenhuma narração ainda.</div>';
            return;
        }
        container.innerHTML = items.map(t =>
            '<div class="tts-item">' +
                '<div style="min-width:0;flex:1;">' +
                    '<div style="font-size:.82rem;color:var(--text-secondary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:520px;">' + esc(t.preview) + '</div>' +
                    '<div class="meta">' + esc(t.provider) + ' · ' + esc(t.voice) + ' · ' + t.chars + ' caracteres · ' + esc(t.created_at) + ' · ' +
                    (t.status === 'completed' ? '<span style="color:var(--success);">concluída</span>' : t.status === 'failed' ? '<span style="color:var(--danger);">falhou</span>' : '<span style="color:var(--warning);">processando</span>') + '</div>' +
                    (t.status === 'completed' ? '<audio controls preload="none" src="/admin/api/tts.php?action=audio&id=' + t.id + '" style="width:100%;max-width:420px;height:32px;margin-top:6px;"></audio>' : (t.error ? '<div style="font-size:.75rem;color:var(--danger);margin-top:2px;">' + esc(t.error) + '</div>' : '')) +
                '</div>' +
                '<div style="display:flex;gap:6px;flex-shrink:0;">' +
                    (t.status === 'completed' ? '<a class="btn btn-outline btn-sm" href="/admin/api/tts.php?action=audio&id=' + t.id + '" download><i class="fas fa-download"></i></a>' : '') +
                    '<button class="btn btn-outline btn-sm" onclick="deleteTts(' + t.id + ')"><i class="fas fa-trash"></i></button>' +
                '</div>' +
            '</div>'
        ).join('');
    }

    async function deleteTts(id) {
        if (!confirm('Excluir esta narração?')) return;
        const resp = await fetch('/admin/api/tts.php', { method: 'POST', body: new URLSearchParams({ action: 'delete', id }) });
        const data = await resp.json();
        if (data.success) { showToast('Narração excluída', 'success'); loadHistory(); }
        else showToast(data.error || 'Erro', 'error');
    }

    loadConfig();
    loadTranscriptOptions();
    loadHistory();
    updateCounter();
    </script>
</body>
</html>
