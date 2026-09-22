<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Plans.php';

Auth::requireAuth();

$user = Auth::user();
if (!Plans::hasFeature($user['plan'], 'videos') && !Plans::hasFeature($user['plan'], 'video') && !Auth::isAdmin()) {
    header('Location: /admin/plan.php?upgrade=1');
    exit;
}

$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vídeos - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .video-card { background:var(--bg-card); border:1px solid var(--border-color); border-radius:var(--radius-lg); overflow:hidden; display:flex; flex-direction:column; }
        .video-card video, .video-card .cover { width:100%; max-height:220px; background:#000; }
        .video-card .v-body { padding:12px; display:flex; flex-direction:column; gap:8px; }
        .video-card .v-meta { font-size:.72rem; color:var(--text-secondary); }
        .video-card .v-actions { display:flex; gap:6px; flex-wrap:wrap; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Vídeos</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Vídeos (ffmpeg)</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Corte, capa, legenda queimada e variações — cada operação preserva o original (branches).</p>
                    </div>
                </div>

                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Upload direto até 24MB, ou importe por URL (até 200MB). Para hospedar e exibir, use o <a href="/admin/video.php"><strong>Player das Páginas</strong></a> (URL externa, YouTube ou parceiros como Drift).
                </div>

                <div class="card" style="margin-bottom:24px;">
                    <div class="card-header"><h3><i class="fas fa-upload"></i> Adicionar vídeo</h3></div>
                    <div class="card-body">
                        <div class="grid-2">
                            <div class="form-group" style="margin:0;">
                                <label>Upload (MP4, MOV, WEBM, MKV, AVI — até 24MB)</label>
                                <div style="display:flex;gap:8px;">
                                    <input type="file" id="videoFile" class="form-control" accept="video/*">
                                    <button class="btn btn-primary" onclick="uploadVideo()"><i class="fas fa-upload"></i> Enviar</button>
                                </div>
                            </div>
                            <div class="form-group" style="margin:0;">
                                <label>Importar por URL (até 200MB)</label>
                                <div style="display:flex;gap:8px;">
                                    <input type="url" id="videoUrl" class="form-control" placeholder="https://.../video.mp4">
                                    <button class="btn btn-outline" onclick="importVideo()"><i class="fas fa-download"></i> Importar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="videosGrid" class="grid-3"></div>
            </div>
        </div>
    </div>

    <input type="file" id="srtFile" accept=".srt" style="display:none;">

    <script src="/assets/js/app.js"></script>
    <script>
    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    let srtTarget = '';

    function fmtDuration(s) {
        s = Math.round(Number(s) || 0);
        return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
    }

    function fmtSize(b) {
        b = Number(b) || 0;
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        return Math.max(1, Math.round(b / 1024)) + ' KB';
    }

    async function loadVideos() {
        const grid = document.getElementById('videosGrid');
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:1.6rem;color:var(--accent);"></i></div>';
        const resp = await fetch('/admin/api/videos.php?action=list');
        const data = await resp.json();
        const items = data.items || [];
        if (data.error) { grid.innerHTML = '<div class="alert alert-warning" style="grid-column:1/-1;">' + esc(data.error) + '</div>'; return; }
        if (!items.length) {
            grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><i class="fas fa-film"></i><h3>Nenhum vídeo ainda</h3><p>Envie ou importe um vídeo para começar.</p></div>';
            return;
        }
        grid.innerHTML = items.map(v => {
            const dl = '/admin/api/videos.php?action=download&name=' + encodeURIComponent(v.name);
            const isVideo = v.kind === 'video';
            const media = isVideo
                ? '<video src="' + dl + '" preload="metadata" controls></video>'
                : '<img class="cover" src="' + dl + '" loading="lazy">';
            const meta = isVideo
                ? fmtDuration(v.duration) + ' · ' + (v.width || '?') + 'x' + (v.height || '?') + ' · ' + fmtSize(v.size)
                : 'capa · ' + fmtSize(v.size);
            return '<div class="video-card">' + media +
                '<div class="v-body">' +
                    '<div style="font-weight:600;font-size:.82rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="' + esc(v.name) + '">' + esc(v.name) + '</div>' +
                    '<div class="v-meta">' + esc(meta) + '</div>' +
                    '<div class="v-actions">' +
                        (isVideo ? '<button class="btn btn-outline btn-sm" onclick="cutVideo(\'' + esc(v.name) + '\')" title="Cortar trecho"><i class="fas fa-scissors"></i></button>' : '') +
                        (isVideo ? '<button class="btn btn-outline btn-sm" onclick="coverVideo(\'' + esc(v.name) + '\')" title="Extrair capa"><i class="fas fa-image"></i></button>' : '') +
                        (isVideo ? '<button class="btn btn-outline btn-sm" onclick="askSubtitle(\'' + esc(v.name) + '\')" title="Queimar legenda .srt"><i class="fas fa-closed-captioning"></i></button>' : '') +
                        (isVideo ? '<button class="btn btn-outline btn-sm" onclick="varyVideo(\'' + esc(v.name) + '\')" title="Criar variação (muda o hash)"><i class="fas fa-wand-magic-sparkles"></i></button>' : '') +
                        '<a class="btn btn-outline btn-sm" href="' + dl + '" title="Baixar"><i class="fas fa-download"></i></a>' +
                        '<button class="btn btn-outline btn-sm" onclick="deleteVideo(\'' + esc(v.name) + '\')" title="Excluir"><i class="fas fa-trash"></i></button>' +
                    '</div>' +
                '</div></div>';
        }).join('');
    }

    async function uploadVideo() {
        const input = document.getElementById('videoFile');
        if (!input.files.length) { showToast('Escolha um arquivo de vídeo.', 'warning'); return; }
        const form = new FormData();
        form.append('action', 'upload');
        form.append('file', input.files[0]);
        showToast('Enviando...', 'info');
        const resp = await fetch('/admin/api/videos.php', { method: 'POST', body: form });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        input.value = '';
        showToast('Vídeo adicionado.', 'success');
        loadVideos();
    }

    async function importVideo() {
        const url = document.getElementById('videoUrl').value.trim();
        if (!url) { showToast('Informe a URL do vídeo.', 'warning'); return; }
        showToast('Importando (pode levar minutos)...', 'info');
        const resp = await fetch('/admin/api/videos.php', { method: 'POST', body: new URLSearchParams({ action: 'import', url }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        document.getElementById('videoUrl').value = '';
        showToast('Vídeo importado.', 'success');
        loadVideos();
    }

    async function deleteVideo(name) {
        if (!confirm('Excluir ' + name + '?')) return;
        await fetch('/admin/api/videos.php', { method: 'POST', body: new URLSearchParams({ action: 'delete', name }) });
        loadVideos();
    }

    async function cutVideo(name) {
        const start = prompt('Início do corte (segundos):', '0');
        if (start === null) return;
        const end = prompt('Fim do corte (segundos):', '30');
        if (end === null) return;
        showToast('Cortando...', 'info');
        const resp = await fetch('/admin/api/videos.php', { method: 'POST', body: new URLSearchParams({ action: 'cut', name, start, end }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Corte pronto: ' + data.name, 'success');
        loadVideos();
    }

    async function coverVideo(name) {
        const second = prompt('Capa em qual segundo?', '1');
        if (second === null) return;
        const resp = await fetch('/admin/api/videos.php', { method: 'POST', body: new URLSearchParams({ action: 'cover', name, second }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Capa extraída: ' + data.name, 'success');
        loadVideos();
    }

    function askSubtitle(name) {
        srtTarget = name;
        document.getElementById('srtFile').click();
    }

    document.getElementById('srtFile').addEventListener('change', async (e) => {
        if (!e.target.files.length || !srtTarget) return;
        const form = new FormData();
        form.append('action', 'subtitle');
        form.append('name', srtTarget);
        form.append('srt', e.target.files[0]);
        e.target.value = '';
        showToast('Queimando legenda (pode levar minutos)...', 'info');
        const resp = await fetch('/admin/api/videos.php', { method: 'POST', body: form });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Legenda queimada: ' + data.name, 'success');
        loadVideos();
    });

    async function varyVideo(name) {
        showToast('Gerando variação (pode levar minutos)...', 'info');
        const resp = await fetch('/admin/api/videos.php', { method: 'POST', body: new URLSearchParams({ action: 'vary', name }) });
        const data = await resp.json();
        if (data.error) { showToast(data.error, 'error'); return; }
        showToast('Variação pronta: ' + data.name, 'success');
        loadVideos();
    }

    loadVideos();
    </script>
</body>
</html>
