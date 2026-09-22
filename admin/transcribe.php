<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Ai/SttConfig.php';
require_once Config::getLibDir() . '/Ai/SttQuota.php';
require_once Config::getLibDir() . '/Settings.php';

Auth::requireAuth();

$user = Auth::user();
$isAdmin = Auth::isAdmin();
$theme = $_SESSION['theme'] ?? 'light';
$quota = SttQuota::check((int)$user['id'], $user['plan']);
$available = SttConfig::isAvailable((int)$user['id']);
$config = SttConfig::forUser((int)$user['id']);
$maxUpload = min(24, (int)ini_get('upload_max_filesize') ?: 24);
// Banner de cota da plataforma (STT+TTS compartilham o limite diario da conta CF)
$platformQuotaOut = false;
try {
    $quotaOutAt = (string)Settings::get('ai_quota_error_at', '');
    $platformQuotaOut = $quotaOutAt !== '' && substr($quotaOutAt, 0, 10) === date('Y-m-d')
        && ($config['source'] ?? '') !== 'byok';
} catch (Throwable $e) {
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transcrições - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .transcript-box { background:var(--bg-secondary); border-radius:var(--radius); padding:16px; font-size:.88rem; line-height:1.7; white-space:pre-wrap; max-height:420px; overflow-y:auto; }
        .transcript-item { display:flex; justify-content:space-between; align-items:center; gap:12px; padding:12px 14px; border-bottom:1px solid var(--border-color); }
        .transcript-item:last-child { border-bottom:none; }
        .transcript-item .meta { font-size:.72rem; color:var(--text-secondary); margin-top:2px; }
        .upload-drop { border:2px dashed var(--border-color); border-radius:var(--radius); padding:20px; text-align:center; color:var(--text-secondary); cursor:pointer; transition:border-color .15s; }
        .upload-drop:hover { border-color:var(--accent); }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Transcrições</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Transcrições (STT)</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Transcreva VSLs, áudios e vídeos com timestamps — pronto para usar como roteiro, legenda ou análise.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="quota-pill" id="quotaPill"><i class="fas fa-microphone-lines"></i> Transcrições: <strong><?= $quota['source'] === 'byok' ? 'BYOK — sem limite' : ($quota['limit'] === -1 ? 'ilimitado' : $quota['used'] . '/' . $quota['limit']) ?></strong></span>
                        <span class="quota-pill" title="Provider ativo"><i class="fas fa-robot"></i> <strong><?= htmlspecialchars($config['provider']) ?></strong> <?= $config['source'] === 'byok' ? '(BYOK)' : '(plataforma)' ?></span>
                    </div>
                </div>

                <?php if (!$available): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> Transcrição não configurada. Configure o Cloudflare Workers AI da plataforma ou sua própria chave em <a href="/admin/ai-settings.php"><strong>IA (BYOK)</strong></a>.
                </div>
                <?php endif; ?>

                <?php if ($platformQuotaOut): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> <strong>Cota da plataforma esgotada hoje.</strong> STT e TTS compartilham o limite diário gratuito — ele renova à meia-noite. Para continuar agora, configure sua própria chave em <a href="/admin/settings.php"><strong>Configurações → Avançado → IA (chaves próprias)</strong></a>.
                </div>
                <?php endif; ?>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header"><h3><i class="fas fa-wand-magic-sparkles"></i> Nova transcrição</h3></div>
                    <div class="card-body">
                        <div class="form-group">
                            <label>URL da página de vendas, do vídeo ou do áudio</label>
                            <div class="input-group">
                                <input type="text" id="sttUrl" class="form-control" placeholder="https://pagina-de-vendas.com ou https://cdn.site.com/vsl.mp4" value="<?= htmlspecialchars($_GET['url'] ?? '') ?>">
                                <button class="btn btn-primary" id="sttBtn" onclick="runTranscribe()"><i class="fas fa-microphone-lines"></i> Transcrever</button>
                            </div>
                            <label style="display:flex;align-items:center;gap:6px;margin-top:8px;font-weight:400;font-size:.85rem;cursor:pointer;">
                                <input type="checkbox" id="sttDetect" checked> Detectar automaticamente a mídia da página (VSL, player, og:video)
                            </label>
                        </div>

                        <div style="display:flex;align-items:center;gap:12px;margin:16px 0;">
                            <div style="flex:1;height:1px;background:var(--border-color);"></div>
                            <span style="font-size:.75rem;color:var(--text-secondary);">ou envie um arquivo (até <?= $maxUpload ?>MB)</span>
                            <div style="flex:1;height:1px;background:var(--border-color);"></div>
                        </div>

                        <div class="upload-drop" onclick="document.getElementById('sttFile').click()">
                            <i class="fas fa-cloud-arrow-up" style="font-size:1.6rem;display:block;margin-bottom:6px;"></i>
                            <span id="sttFileName">Clique para selecionar áudio/vídeo (mp3, mp4, m4a, wav...)</span>
                            <input type="file" id="sttFile" accept="audio/*,video/*" style="display:none;" onchange="onFileSelected(this)">
                        </div>
                    </div>
                </div>

                <div id="sttLoading" style="display:none;">
                    <div class="card" style="margin-bottom:24px;"><div class="card-body" style="text-align:center;padding:30px;">
                        <i class="fas fa-spinner fa-spin" style="font-size:1.8rem;color:var(--accent);"></i>
                        <p style="margin-top:10px;color:var(--text-secondary);">Transcrevendo... VSLs longas podem levar alguns minutos.</p>
                    </div></div>
                </div>

                <div id="sttResult" style="display:none;margin-bottom:24px;">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-file-lines"></i> Resultado</h3>
                            <div style="display:flex;gap:8px;">
                                <button class="btn btn-outline btn-sm" onclick="copyTranscript()"><i class="fas fa-copy"></i> Copiar</button>
                                <button class="btn btn-outline btn-sm" onclick="downloadSrt()"><i class="fas fa-closed-captioning"></i> Baixar SRT</button>
                                <button class="btn btn-outline btn-sm" onclick="downloadTxt()"><i class="fas fa-download"></i> Baixar TXT</button>
                                <button class="btn btn-outline btn-sm" onclick="askAgentTranscript()"><i class="fas fa-handshake"></i> Discutir com o Sócio de IA</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="sttMeta" style="font-size:.75rem;color:var(--text-secondary);margin-bottom:10px;"></div>
                            <div class="transcript-box" id="sttText"></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header"><h3><i class="fas fa-clock-rotate-left"></i> Histórico</h3></div>
                    <div class="card-body" id="sttHistory" style="padding:0;">
                        <div style="text-align:center;color:var(--text-secondary);padding:24px;">Carregando...</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="/assets/js/app.js"></script>
    <script>
    let lastWords = [];
    let lastText = '';

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function updateQuota(quota) {
        if (!quota) return;
        const label = quota.source === 'byok' ? 'BYOK — sem limite' : (quota.limit === -1 ? 'ilimitado' : quota.used + '/' + quota.limit);
        document.getElementById('quotaPill').innerHTML = '<i class="fas fa-microphone-lines"></i> Transcrições: <strong>' + label + '</strong>';
    }

    function onFileSelected(input) {
        const name = input.files && input.files[0] ? input.files[0].name : '';
        document.getElementById('sttFileName').textContent = name || 'Clique para selecionar áudio/vídeo (mp3, mp4, m4a, wav...)';
    }

    async function runTranscribe() {
        const url = document.getElementById('sttUrl').value.trim();
        const fileInput = document.getElementById('sttFile');
        const file = fileInput.files && fileInput.files[0];

        if (!url && !file) { showToast('Informe uma URL ou envie um arquivo', 'warning'); return; }

        const body = new FormData();
        body.append('action', 'transcribe');
        if (file) {
            body.append('file', file);
        } else {
            body.append('url', url);
            body.append('detect', document.getElementById('sttDetect').checked ? '1' : '0');
        }

        document.getElementById('sttLoading').style.display = 'block';
        document.getElementById('sttResult').style.display = 'none';

        try {
            const resp = await fetch('/admin/api/transcribe.php', { method: 'POST', body });
            const data = await resp.json();
            if (data.error) { showToast(data.error, 'error'); return; }
            renderResult(data);
            updateQuota(data.quota);
            loadHistory();
        } catch (err) {
            showToast('Erro de conexão: ' + err.message, 'error');
        } finally {
            document.getElementById('sttLoading').style.display = 'none';
        }
    }

    function renderResult(data) {
        lastWords = data.words || [];
        lastText = data.text || '';
        document.getElementById('sttText').textContent = lastText || '(vazio)';
        document.getElementById('sttMeta').textContent = (data.provider || '') + ' · ' + (lastWords.length ? lastWords.length + ' palavras com timestamp' : 'sem timestamps') + (data.duration ? ' · ' + formatDuration(data.duration) : '');
        document.getElementById('sttResult').style.display = 'block';
    }

    function formatDuration(seconds) {
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        return m + 'min ' + String(s).padStart(2, '0') + 's';
    }

    function copyTranscript() {
        if (!lastText) return;
        navigator.clipboard.writeText(lastText).then(() => showToast('Texto copiado', 'success'));
    }

    function downloadTxt() {
        if (!lastText) return;
        const blob = new Blob([lastText], { type: 'text/plain;charset=utf-8' });
        downloadBlob(blob, 'transcricao.txt');
    }

    function askAgentTranscript() {
        if (!lastText) return;
        const text = 'Transcrevi este material de VSL. Analise o roteiro e me diga: os angulos de venda, o publico e 3 melhorias praticas. Trecho: ' + lastText.substring(0, 600);
        window.open('/admin/agent.php?ask=' + encodeURIComponent(text), '_blank');
    }

    function downloadSrt() {
        if (!lastWords.length) { showToast('Esta transcrição não tem timestamps para gerar SRT', 'warning'); return; }
        const lines = [];
        let index = 1;
        let chunk = [];
        let start = null;
        let end = null;

        const flush = () => {
            if (!chunk.length) return;
            lines.push(index++);
            lines.push(srtTime(start) + ' --> ' + srtTime(end));
            lines.push(chunk.join(' '));
            lines.push('');
            chunk = [];
            start = null;
        };

        for (const w of lastWords) {
            if (start === null) start = w.start;
            end = w.end;
            chunk.push(w.word);
            const tooLong = chunk.length >= 14 || (end - start) >= 6;
            const pause = end !== null && lastWords[lastWords.indexOf(w) + 1] && (lastWords[lastWords.indexOf(w) + 1].start - end) > 0.8;
            if (tooLong || pause) flush();
        }
        flush();

        const blob = new Blob([lines.join('\n')], { type: 'text/plain;charset=utf-8' });
        downloadBlob(blob, 'transcricao.srt');
    }

    function srtTime(seconds) {
        const h = String(Math.floor(seconds / 3600)).padStart(2, '0');
        const m = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');
        const s = String(Math.floor(seconds % 60)).padStart(2, '0');
        const ms = String(Math.round((seconds % 1) * 1000)).padStart(3, '0');
        return h + ':' + m + ':' + s + ',' + ms;
    }

    function downloadBlob(blob, filename) {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = filename;
        a.click();
        URL.revokeObjectURL(a.href);
    }

    async function loadHistory() {
        const resp = await fetch('/admin/api/transcribe.php?action=list');
        const data = await resp.json();
        const container = document.getElementById('sttHistory');
        const items = data.items || [];
        if (!items.length) {
            container.innerHTML = '<div style="text-align:center;color:var(--text-secondary);padding:24px;">Nenhuma transcrição ainda.</div>';
            return;
        }
        container.innerHTML = items.map(t =>
            '<div class="transcript-item">' +
                '<div style="min-width:0;">' +
                    '<div style="font-size:.85rem;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:520px;">' + esc(t.source_url) + '</div>' +
                    '<div class="meta">' + esc(t.provider) + ' · ' + esc(t.created_at) + (t.duration_seconds ? ' · ' + formatDuration(t.duration_seconds) : '') + ' · ' +
                    (t.status === 'completed' ? '<span style="color:var(--success);">concluída</span>' : t.status === 'failed' ? '<span style="color:var(--danger);">falhou</span>' : '<span style="color:var(--warning);">processando</span>') + '</div>' +
                    '<div style="font-size:.78rem;color:var(--text-secondary);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:520px;">' + esc(t.preview || t.error || '') + '</div>' +
                '</div>' +
                '<div style="display:flex;gap:6px;flex-shrink:0;">' +
                    (t.status === 'completed' ? '<button class="btn btn-outline btn-sm" onclick="openTranscript(' + t.id + ')"><i class="fas fa-eye"></i></button>' : '') +
                    '<button class="btn btn-outline btn-sm" onclick="deleteTranscript(' + t.id + ')"><i class="fas fa-trash"></i></button>' +
                '</div>' +
            '</div>'
        ).join('');
    }

    async function openTranscript(id) {
        const resp = await fetch('/admin/api/transcribe.php?action=get&id=' + id);
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        renderResult({
            text: data.transcription.text,
            words: data.transcription.words,
            duration: data.transcription.duration_seconds,
            provider: data.transcription.provider,
        });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    async function deleteTranscript(id) {
        if (!confirm('Excluir esta transcrição?')) return;
        const resp = await fetch('/admin/api/transcribe.php', { method: 'POST', body: new URLSearchParams({ action: 'delete', id }) });
        const data = await resp.json();
        if (data.success) { showToast('Transcrição excluída', 'success'); loadHistory(); }
        else showToast(data.error || 'Erro', 'error');
    }

    loadHistory();
    </script>
</body>
</html>
