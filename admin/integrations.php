<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Plans.php';
require_once Config::getLibDir() . '/Social/SocialNetworks.php';
require_once Config::getLibDir() . '/Social/SocialOAuth.php';
Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
$user = Auth::user();
$plan = (string)$user['plan'];
$hasFeature = Plans::hasFeature($plan, 'social');
$isAdmin = Auth::isAdmin();
$oauthMsg = null;
if (isset($_GET['oauth'])) {
    $oauthMsg = $_GET['oauth'] === 'ok'
        ? ['ok' => true, 'text' => 'Conta conectada com sucesso!']
        : ['ok' => false, 'text' => 'Falha na conexão: ' . htmlspecialchars($_GET['msg'] ?? 'erro desconhecido', ENT_QUOTES)];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="/assets/img/favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrações — AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .net-card { display:flex; gap:14px; align-items:flex-start; padding:18px; }
        .net-icon { width:46px; height:46px; border-radius:12px; display:flex; align-items:center; justify-content:center; font-size:1.3rem; flex-shrink:0; }
        .net-body { flex:1; min-width:0; }
        .net-body h3 { margin:0; font-size:1rem; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .net-body p { margin:4px 0 10px; font-size:.82rem; color:var(--text-secondary); }
        .pill { display:inline-flex; align-items:center; gap:5px; font-size:.72rem; font-weight:600; padding:3px 9px; border-radius:20px; background:var(--bg-secondary,#eee); border:1px solid var(--border,#ddd); }
        .pill.ok { background:#e6f6ec; border-color:#9fdcb8; color:#14713d; }
        .pill.warn { background:#fff6e0; border-color:#f0d391; color:#8a6100; }
        .pill.err { background:#fdecec; border-color:#f0a8a8; color:#a32020; }
        [data-theme="dark"] .pill.ok { background:#123f26; border-color:#1d6b41; color:#7ee0a8; }
        [data-theme="dark"] .pill.warn { background:#4a3a10; border-color:#7a6122; color:#ffd979; }
        [data-theme="dark"] .pill.err { background:#4a1a1a; border-color:#7a2e2e; color:#ff9c9c; }
        .net-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
        .net-checks { display:flex; gap:8px; flex-wrap:wrap; margin:12px 0; }
        .net-check { display:flex; align-items:center; gap:7px; padding:8px 13px; border:1.5px solid var(--border,#ddd); border-radius:10px; cursor:pointer; font-size:.85rem; user-select:none; }
        .net-check.on { border-color:var(--accent,#0b5ed7); background:color-mix(in srgb, var(--accent,#0b5ed7) 8%, transparent); }
        .net-check input { display:none; }
        .conn-step { margin-bottom:14px; }
        .conn-step-h { display:flex; align-items:center; gap:8px; font-weight:600; font-size:.88rem; margin-bottom:8px; }
        .conn-step-n { width:22px; height:22px; border-radius:50%; background:var(--accent,#0b5ed7); color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:.72rem; flex-shrink:0; }
        .conn-steps { margin:0; padding-left:20px; font-size:.82rem; color:var(--text-secondary); line-height:1.55; }
        .conn-steps li { margin:5px 0; }
        .conn-steps a { color:var(--accent,#0b5ed7); }
        .conn-steps code, .conn-step code { background:var(--bg-secondary,#f0f0f0); padding:1px 5px; border-radius:4px; font-size:.78rem; word-break:break-all; }
        .conn-method { border:1.5px solid var(--border,#ddd); border-radius:12px; padding:12px 14px; margin-top:10px; }
        .conn-hint { font-size:.78rem; color:var(--text-secondary); margin-top:3px; line-height:1.5; }
        .sec-title { display:flex; justify-content:space-between; align-items:center; margin:26px 0 12px; gap:12px; flex-wrap:wrap; }
        .sec-title h2 { margin:0; font-size:1.1rem; }
        .empty { text-align:center; padding:26px; color:var(--text-secondary); font-size:.88rem; }
        details.guide > summary { cursor:pointer; list-style:none; }
        details.guide > summary::-webkit-details-marker { display:none; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Integrações</div>
                <div class="topbar-actions">
                    <span class="pill" id="quotaPill"><i class="fas fa-spinner fa-spin"></i></span>
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <h1>Redes sociais</h1>
                    <p style="color:var(--text-secondary);font-size:.9rem;">Conecte suas contas e publique em várias redes de uma vez — manualmente, agendado ou pelo Sócio.</p>
                </div>

                <?php if ($oauthMsg): ?>
                <div class="alert alert-<?= $oauthMsg['ok'] ? 'success' : 'danger' ?>">
                    <i class="fas fa-<?= $oauthMsg['ok'] ? 'check-circle' : 'exclamation-triangle' ?>"></i> <?= $oauthMsg['text'] ?>
                </div>
                <?php endif; ?>

                <div id="featureAlert" class="alert alert-warning" style="display:none;">
                    <i class="fas fa-lock"></i> Seu plano não inclui publicações sociais.
                    <a href="/admin/plan.php">Fazer upgrade</a> para liberar conexões e publicações.
                </div>

                <?php if ($isAdmin): ?>
                <details class="card guide" id="ownerGuideCard" style="margin-bottom:6px;">
                    <summary style="padding:14px 18px;font-weight:600;font-size:.9rem;">
                        <i class="fas fa-graduation-cap" style="color:var(--accent,#0b5ed7);"></i>
                        Guia do dono — configurar o app Meta uma vez (só você vê — o cliente nunca vê nada disto)
                    </summary>
                    <div class="card-body" style="border-top:1px solid var(--border,#ddd);">
                        <p class="conn-hint" style="margin-top:0;">As credenciais ficam no <code>.env</code> da plataforma (<code>META_APP_ID</code> / <code>META_APP_SECRET</code>) — o cliente só clica <b>Conectar</b>. Guia completo e mais detalhado: <code>docs/guia-meta.md</code> no repositório.</p>
                        <ol class="conn-steps">
                            <li><b>Criar o app:</b> <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps</a> → <b>Criar app</b> → caso de uso <b>"Manage everything on your Page"</b> (Gerenciar tudo da sua Página) e, se perguntar o tipo, <b>Something else</b> — nunca Consumer (bloqueia os escopos <code>pages_*</code> com "Invalid Scopes").</li>
                            <li><b>Permissões:</b> menu lateral <b>Use Cases</b> → no caso de uso → <b>Customize</b> → <b>Permissions and features</b> → garanta <b>"Ready for testing"</b> em <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_posts</code>, <code>instagram_basic</code> e <code>instagram_content_publish</code> (adicione via <b>Actions</b> se aparecer outro status) + produto <b>Instagram Graph API</b>.</li>
                            <li><b>Redirect URI:</b> cadastre <code><?= htmlspecialchars(SocialOAuth::redirectUri()) ?></code> em <b>Valid OAuth Redirect URIs</b> — o campo fica em UM destes lugares: <b>Use Cases → Customize → Settings</b> (apps por use case <b>não têm "Add Product"</b>) · <b>Facebook Login for Business → Settings</b> · <b>Add Product → Facebook Login → Settings</b> (layout antigo). Atalho: <code>developers.facebook.com/apps/SEU_APP_ID/fb-login/settings/</code>. A URL precisa bater <b>exatamente</b> com o domínio que você usa no painel (também aparece nos passos do modal "Conectar").</li>
                            <li><b>Credenciais:</b> <b>Settings → Basic</b> → copie o <b>App ID</b> e o <b>App Secret</b> (botão Mostrar) + preencha <b>App Domains</b>=<code>localhost</code>, <b>Site URL</b>=<code>http://localhost:9876</code>, <b>Categoria</b> e <b>Privacy Policy URL</b>=<code>http://localhost:9876/privacidade</code> → <b>Save Changes</b> (sem Categoria/Privacy o save é ignorado).</li>
                            <li><b>.env:</b> cole como <code>META_APP_ID=...</code> e <code>META_APP_SECRET=...</code> e <b>recrie o container</b>: <code>docker-compose up -d --force-recreate</code> — um simples restart não recarrega o .env.</li>
                            <li><b>Modo Development</b> só conecta contas com papel no app (você, quem criou) — clientes em geral só após o app <b>Live + App Review</b> (próximo épico). Teste agora clicando em <b>Conectar</b> numa rede.</li>
                        </ol>
                    </div>
                </details>
                <?php endif; ?>

                <div class="sec-title">
                    <h2>Contas conectadas</h2>
                    <span class="pill" id="connPill">—</span>
                </div>
                <div class="grid-3" id="netGrid">
                    <div class="card"><div class="card-body"><span class="pill"><i class="fas fa-spinner fa-spin"></i> Carregando…</span></div></div>
                </div>

                <div class="sec-title" id="flowsSection">
                    <h2>Fluxos (automação)</h2>
                    <div style="display:flex;gap:8px;align-items:center;">
                        <span class="pill" id="flowsPill">—</span>
                        <button class="btn btn-sm btn-primary" onclick="toggleFlowForm()"><i class="fas fa-plus"></i> Novo fluxo</button>
                    </div>
                </div>
                <div class="card" id="flowFormCard" style="display:none;">
                    <div class="card-body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;">
                            <label style="display:block;font-size:.82rem;color:var(--text-secondary);">Nome
                                <input id="flowName" type="text" class="form-control" maxlength="120" placeholder="Ex.: Postagem diária"
                                    style="width:100%;margin-top:4px;">
                            </label>
                            <label style="display:block;font-size:.82rem;color:var(--text-secondary);">Gatilho
                                <select id="flowTrigger" class="form-control" onchange="renderFlowCfg()" style="width:100%;margin-top:4px;">
                                    <option value="schedule">Horário fixo</option>
                                    <option value="post_published">Após publicar um post</option>
                                </select>
                            </label>
                            <label style="display:block;font-size:.82rem;color:var(--text-secondary);">Ação
                                <select id="flowAction" class="form-control" onchange="renderFlowCfg()" style="width:100%;margin-top:4px;">
                                    <option value="publish_post">Publicar um post</option>
                                    <option value="webhook">Webhook (POST JSON)</option>
                                </select>
                            </label>
                        </div>
                        <div id="flowCfg" style="margin-top:14px;"></div>
                        <div style="display:flex;gap:8px;align-items:center;margin-top:14px;">
                            <button class="btn btn-primary" id="flowSaveBtn" onclick="createFlow()"><i class="fas fa-check"></i> Salvar fluxo</button>
                            <button class="btn" onclick="toggleFlowForm()">Cancelar</button>
                            <span id="flowMsg" style="font-size:.83rem;"></span>
                        </div>
                    </div>
                </div>
                <div class="card" id="flowsCard">
                    <div class="card-body" style="padding:0;overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:.85rem;">
                            <thead><tr style="text-align:left;border-bottom:1px solid var(--border,#ddd);">
                                <th style="padding:11px 14px;">Fluxo</th>
                                <th style="padding:11px 14px;">Gatilho</th>
                                <th style="padding:11px 14px;">Ação</th>
                                <th style="padding:11px 14px;">Última execução</th>
                                <th style="padding:11px 14px;"></th>
                            </tr></thead>
                            <tbody id="flowsBody">
                                <tr><td colspan="5" class="empty"><i class="fas fa-spinner fa-spin"></i></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="modal-overlay" id="connModal">
        <div class="modal" style="max-width:660px;">
            <div class="modal-header">
                <h3 id="connTitle">Conectar</h3>
                <button class="modal-close" onclick="closeConnModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display:flex;gap:12px;align-items:center;margin-bottom:14px;">
                    <div class="net-icon" id="connNetIcon" style="width:42px;height:42px;font-size:1.15rem;"></div>
                    <p id="connNetDesc" style="margin:0;font-size:.85rem;color:var(--text-secondary);"></p>
                </div>

                <?php if ($isAdmin): ?>
                <div class="conn-step">
                    <div class="conn-step-h"><span class="conn-step-n">1</span> Pré-requisitos (1 vez nesta rede)</div>
                    <ol class="conn-steps" id="connSteps"></ol>
                </div>
                <?php endif; ?>

                <div class="conn-step">
                    <div class="conn-step-h"><span class="conn-step-n"><?= $isAdmin ? '2' : '1' ?></span> Conectar</div>

                    <div class="conn-method">
                        <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
                            <div>
                                <b style="font-size:.88rem;">Entrar com login da rede</b>
                                <div class="conn-hint" id="connOAuthHint"></div>
                            </div>
                            <button class="btn btn-sm btn-primary" id="connOAuthBtn" onclick="startOAuth()">
                                <i class="fas fa-arrow-right-from-bracket"></i> <span id="connOAuthLabel">Entrar</span>
                            </button>
                        </div>
                    </div>

                    <?php if ($isAdmin): ?>
                    <div class="conn-method">
                        <details id="connCredsBox" style="margin-top:0;">
                            <summary style="cursor:pointer;font-size:.84rem;font-weight:600;list-style:none;">
                                <i class="fas fa-key"></i> <b>Configuração avançada (só admin)</b> — usar meu próprio app (App ID + Secret)
                            </summary>
                            <div class="conn-hint" id="connCredsHelp" style="margin:6px 0;"></div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
                                <input id="connAppId" class="form-control" placeholder="App ID / Client ID / Client Key" style="flex:1;min-width:150px;" autocomplete="off">
                                <input id="connAppSecret" type="password" class="form-control" placeholder="App Secret / Client Secret" style="flex:1;min-width:150px;" autocomplete="off">
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                                <button class="btn btn-sm" id="connCredsSaveBtn" onclick="saveAppCreds()"><i class="fas fa-floppy-disk"></i> Salvar credenciais</button>
                                <button class="btn btn-sm" id="connCredsDelBtn" onclick="delAppCreds()" style="display:none;">Remover</button>
                                <span id="connCredsMsg" class="conn-hint"></span>
                            </div>
                        </details>
                    </div>

                    <div class="conn-method">
                        <b style="font-size:.88rem;">…ou cole um token manual</b>
                        <div class="conn-hint" id="connTokenHint"></div>
                        <div style="display:flex;gap:8px;margin-top:8px;">
                            <input type="password" id="connToken" class="form-control" placeholder="Token de acesso" style="flex:1;" autocomplete="off">
                            <button class="btn btn-sm btn-primary" id="connTokenBtn" onclick="saveManualToken()">Validar e conectar</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div id="connMsg" style="margin-top:12px;font-size:.83rem;"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="/assets/js/app.js"></script>
    <script>
    const HAS_FEATURE = <?= $hasFeature ? 'true' : 'false' ?>;
    const IS_ADMIN = <?= $isAdmin ? 'true' : 'false' ?>;
    const API = '/admin/api/social.php';
    const FLOWS_API = '/admin/api/flows.php';
    const TRIG_PT = { schedule: 'Horário fixo', post_published: 'Após publicar post', manual: 'manual' };
    const ACT_PT = { publish_post: 'Publicar post', webhook: 'Webhook (POST JSON)' };
    const DAY_NAMES = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    let STATE = { networks: {}, connections: [], flows: [] };

    async function api(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        if (data) Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' });
        let j = null;
        try { j = await r.json(); } catch (e) { j = { error: 'Resposta inválida do servidor' }; }
        if (!r.ok && !j.error) j.error = 'Erro HTTP ' + r.status;
        return j;
    }

    async function flowApi(action, data) {
        const fd = new FormData();
        fd.append('action', action);
        if (data) Object.entries(data).forEach(([k, v]) => fd.append(k, v));
        const r = await fetch(FLOWS_API, { method: 'POST', body: fd, credentials: 'same-origin' });
        let j = null;
        try { j = await r.json(); } catch (e) { j = { error: 'Resposta inválida do servidor' }; }
        if (!r.ok && !j.error) j.error = 'Erro HTTP ' + r.status;
        return j;
    }

    function fmtDate(s) {
        if (!s) return '—';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    function esc(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }

    async function loadConnections() {
        const j = await api('connections');
        if (j.error) { document.getElementById('netGrid').innerHTML = '<div class="empty">' + esc(j.error) + '</div>'; return; }
        STATE.networks = j.networks || {};
        STATE.connections = j.connections || [];
        STATE.connQuota = j.conn_quota;
        STATE.postQuota = j.post_quota;

        document.getElementById('featureAlert').style.display = j.has_feature ? 'none' : '';
        ['flowsSection', 'flowsCard'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.style.display = j.has_feature ? '' : 'none';
        });
        const ff = document.getElementById('flowFormCard');
        if (ff) ff.style.display = 'none';

        const cq = j.conn_quota || {};
        document.getElementById('connPill').innerHTML = cq.max === -1
            ? '<i class="fas fa-link"></i> ' + (cq.used || 0) + ' conexões (ilimitado)'
            : '<i class="fas fa-link"></i> ' + (cq.used || 0) + '/' + (cq.max ?? 0) + ' conexões';
        document.getElementById('quotaPill').innerHTML = j.has_feature
            ? '<i class="fas fa-circle" style="color:#28a745;font-size:.6rem;"></i> Social ativo'
            : '<i class="fas fa-lock"></i> Social: upgrade';

        const connByNet = {};
        (j.connections || []).forEach(c => connByNet[c.network] = c);

        const grid = document.getElementById('netGrid');
        grid.innerHTML = '';
        Object.entries(j.networks).forEach(([n, m]) => {
            const c = connByNet[n];
            const div = document.createElement('div');
            div.className = 'card';
            div.innerHTML = `
                <div class="card-body net-card">
                    <div class="net-icon" style="background:${m.color}1f;color:${m.color};"><i class="${m.icon}"></i></div>
                    <div class="net-body">
                        <h3>${esc(m.name)}
                            ${m.beta ? '<span class="pill warn">beta</span>' : ''}
                            ${c && c.status === 'connected'
                                ? '<span class="pill ok"><i class="fas fa-check"></i> conectada</span>'
                                : c ? '<span class="pill err"><i class="fas fa-exclamation"></i> ' + esc(c.status === 'expired' ? 'expirada' : c.status) + '</span>'
                                : '<span class="pill">não conectada</span>'}
                        </h3>
                        <p>${esc(m.desc)} · ${m.media === 'video' ? 'vídeo' : 'imagem'}${m.media_required ? ' (obrigatório)' : ' (opcional)'}</p>
                        ${c
                            ? (c.status === 'connected'
                                ? `<div class="net-actions">
                                     <span style="font-size:.8rem;"><i class="fas fa-circle-user"></i> ${esc(c.account_name || c.account_id)}</span>
                                     <button class="btn btn-sm" onclick="disconnect('${n}')"><i class="fas fa-unlink"></i> Desconectar</button>
                                   </div>`
                                : `<div class="net-actions">
                                     <button class="btn btn-sm btn-primary" onclick="openConnModal('${n}')"><i class="fas fa-plug"></i> Reconectar</button>
                                     <button class="btn btn-sm" onclick="disconnect('${n}')"><i class="fas fa-unlink"></i> Desconectar</button>
                                     <span class="conn-hint">${c.status === 'expired' ? 'token expirado' : 'conexão com erro'}</span>
                                   </div>`)
                            : `<div class="net-actions">
                                 <button class="btn btn-sm btn-primary" onclick="openConnModal('${n}')"><i class="fas fa-plug"></i> Conectar</button>
                                 <span class="conn-hint">${m.oauth_configured ? (IS_ADMIN ? (m.oauth_source === 'user' ? 'app próprio salvo' : 'app da plataforma (.env)') : '') : (IS_ADMIN ? 'configure o app (guia do dono)' : 'contate o suporte')}</span>
                               </div>`}
                    </div>
                </div>`;
            grid.appendChild(div);
        });
    }

    // ------------------------------------- modal de conexão guiada (Fase 4)

    const REDIRECT_URI = <?= json_encode(SocialOAuth::redirectUri()) ?>;
    const PAGE_HOST = <?= json_encode($_SERVER['HTTP_HOST'] ?? 'SEU-DOMINIO') ?>;
    let CONN_NET = null;

    <?php if ($isAdmin): ?>
    // Jornadas verificadas por rede (pesquisa 2026): pré-requisitos
    // encadeados, links oficiais e armadilhas reais antes de gerar o token.
    // Só admin: o cliente vê apenas o botão Conectar (nada de guias no HTML dele).
    const GUIDES = {
        facebook: {
            title: 'Facebook (Página)',
            steps: [
                '<b>Este guia é para você criar seu próprio app</b> (uma única vez por rede) — você é o dono dele e não depende de aprovação de ninguém; no fim, salve o App ID + Secret no passo 2 e clique em <b>Entrar</b>.',
                'Em <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps</a>: abra o app da plataforma — ou crie um novo do tipo <b>Something else</b> (<b>nunca Consumer</b>: apps Consumer bloqueiam os escopos <code>pages_*</code> com o erro "Invalid Scopes").',
                'Na aba <b>Use Cases</b> (substitui o antigo "Add Product"): use/adicione o caso <b>Gerenciar tudo da sua Página</b> → <b>Customize</b> → <b>Permissions and features</b> e garanta status <b>"Ready for testing"</b> em <code>pages_show_list</code>, <code>pages_read_engagement</code> e <code>pages_manage_posts</code> (se aparecer outro status, clique em <b>Actions</b> e adicione a permissão ao caso) — sem isso o popup recusa com <b>"Invalid Scopes: pages_manage_posts"</b>.',
                'Encontre o campo <b>Valid OAuth Redirect URIs</b> (a lista, não o validador) e cadastre este Redirect URI exato — sem isso o popup é recusado com "URL bloqueada": <code class="conn-uri"></code> — o campo fica em UM destes lugares: <b>(a)</b> menu lateral → <b>Use Cases</b> → no caso de uso (ex.: "Authentication and account creation" ou o seu) → <b>Customize</b> → aba <b>Settings</b> → <b>Valid OAuth Redirect URIs</b> (dashboard novo, que <b>não tem "Add Product"</b>); <b>(b)</b> menu lateral → <b>Facebook Login for Business</b> (pode já ter sido adicionado sozinho) → <b>Settings</b> → <b>Valid OAuth Redirect URIs</b>; <b>(c)</b> menu lateral → <b>Add Product</b> → <b>Facebook Login</b> → <b>Set Up</b> → <b>Facebook Login → Settings → Client OAuth Settings</b> (layout antigo). Atalho: abra <code>developers.facebook.com/apps/SEU_APP_ID/fb-login/settings/</code> (troque SEU_APP_ID) — se a página abrir, é lá.',
                'Settings → Basic: <b>App Domains</b> = <b>localhost</b> (e o domínio de produção quando existir) + <b>Site URL</b> = esta plataforma + <b>Categoria</b> e <b>Privacy Policy URL</b> preenchidos → <b>Save Changes</b> (sem Categoria/Privacy o salvamento é ignorado e o popup falha com "domínio não está incluído nos domínios do app").',
                'Sua conta precisa de papel no app (quem criou já é admin) e o app em modo <b>Development</b> — assim você conecta sua conta agora, <b>sem App Review</b>.',
                'A conta precisa ter uma <b>Página</b> do Facebook (<a href="https://www.facebook.com/pages/create" target="_blank" rel="noopener">facebook.com/pages/create</a>) — publicamos na Página; não é preciso Business Suite/conta de negócio.',
                '<b>Feito:</b> você (quem criou o app é admin dele) conecta agora <b>sem App Review</b>; outras contas entram como <b>testers</b> (App Roles) no seu app, ou publique o app (Live + App Review) para qualquer conta conectar.'
            ],
            token: 'Cole o token long-lived (60 dias) do Access Token Debugger — o token de 1 hora do Explorer expira antes do próximo agendamento.',
            creds: 'App ID + App Secret do seu app Meta (developers.facebook.com/apps → Settings → Basic).'
        },
        instagram: {
            title: 'Instagram (conta profissional)',
            steps: [
                '<b>Usando o mesmo app Meta criado no Facebook (guia do Facebook)</b> — configure uma única vez, salve o App ID/Secret no passo 2 e clique em <b>Entrar</b>.',
                'Siga os passos 2–5 do Facebook no mesmo app Meta: tipo <b>Something else</b> (nunca Consumer), <b>Use Cases → Customize → Permissions and features</b> com <b>"Ready for testing"</b> em <code>pages_show_list</code>, <code>pages_read_engagement</code>, <code>pages_manage_posts</code>, <b><code>instagram_basic</code></b> e <b><code>instagram_content_publish</code></b> — sem eles o popup recusa com <b>"Invalid Scopes: instagram_basic, instagram_content_publish"</b> (o <code>instagram_basic</code> é dependência obrigatória do publish).',
                'Adicione o produto <b>Instagram Graph API</b> (Add Products/Use Cases) no app — sem ele os escopos do IG não são aceitos.',
                'No app do Instagram: Configurações → Conta → <b>Conta profissional</b> (Creator/Business) → conecte à sua Página do Facebook.',
                'Cadastre o mesmo <b>Valid OAuth Redirect URIs</b> do passo 4 do Facebook (Use Cases → Settings, Facebook Login for Business → Settings ou Add Product → Facebook Login → Settings): <code class="conn-uri"></code> — e Settings → Basic com <b>App Domains</b> = <b>localhost</b> + Categoria/Privacy Policy → Save Changes.',
                'Publicação no feed exige <b>imagem</b> (vídeo só via Reels — fora do escopo desta API).',
                'Modo <b>Development</b> conecta você (papel no app) sem App Review; outras contas precisam ser <b>testers</b> no app ou o app precisa ir <b>Live + App Review</b> (Meta, verificação de negócio).'
            ],
            token: 'Cole o token long-lived do Meta (mesmo app e Página do Facebook).',
            creds: 'App ID + App Secret do seu app Meta (o mesmo do Facebook).'
        },
        threads: {
            title: 'Threads',
            steps: [
                'Em <a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com/apps</a> → seu app → produto <b>Threads API</b> → <i>Create Threads App</i>.',
                'Anote o <b>Threads App ID</b> e o <b>Threads App Secret</b> — são diferentes do app Meta.',
                'Cadastre o Redirect URI no produto Threads API: <code class="conn-uri"></code>',
                'Salve Threads App ID + Secret abaixo e use o <b>login oficial</b> (janela própria do Threads, escopos <code>threads_basic</code> + <code>threads_content_publish</code>; token de 60 dias com renovação).',
                'Sem app: gere o token em App Dashboard → Threads API → <i>Access Token Generator</i> e cole abaixo.'
            ],
            token: 'Cole o token do Access Token Generator (Threads API) — dura 60 dias.',
            creds: 'Threads App ID + Threads App Secret (App Dashboard → Threads API).'
        },
        x: {
            title: 'X (Twitter)',
            steps: [
                'Crie um app em <a href="https://developer.x.com/" target="_blank" rel="noopener">developer.x.com</a> (console.x.com) dentro de um Project.',
                '<i>User authentication settings</i> → tipo <b>Web App</b>, permissão <b>Read and write</b>, callback: <code class="conn-uri"></code>',
                '<b>Créditos (obrigatório desde 06/2026)</b>: o X não tem tier gratuito para apps novos — ative os créditos em <i>Billing</i>. Custo por conta: ~$0.015/post sem link e $0.20 com link (pago por você direto à X).',
                'Salve <b>Client ID + Secret</b> abaixo → <b>login oficial</b> renova o token sozinho (ele dura só 2h; sem refresh o agendamento falharia).',
                'Alternativa: gere um token de usuário com escopo <code>tweet.write</code> e cole — sem refresh ele expira em 2h. Bearer de app <b>não serve</b> para postar.'
            ],
            token: 'Token de usuário OAuth 2.0 com escopo tweet.write — bearer de app NÃO publica (403).',
            creds: 'OAuth 2.0 Client ID + Client Secret (console.x.com → User authentication settings).'
        },
        tiktok: {
            title: 'TikTok',
            steps: [
                'Crie o app em <a href="https://developers.tiktok.com/" target="_blank" rel="noopener">developers.tiktok.com</a> → adicione o produto <b>Content Posting API</b> → enable <b>Direct Post</b>.',
                'Escopo <code>video.publish</code> precisa ser ativado no app — até a <b>auditoria</b> da TikTok os posts saem <b>privados</b> (só você vê) e o limite é 5 contas/24h.',
                'Cadastre o Redirect URL: <code class="conn-uri"></code>',
                `Em <i>URL Configuration</i> cadastre o domínio desta plataforma (<b>${PAGE_HOST}</b>) — sem isso o TikTok recusa a URL da mídia (<code>url_ownership_unverified</code>).`,
                'Salve <b>Client Key + Secret</b> abaixo → login oficial; ou gere o token com <code>video.publish</code> e cole.'
            ],
            token: 'Cole o token com o escopo video.publish (gerador OAuth do seu app TikTok).',
            creds: 'Client Key + Client Secret do seu app (developers.tiktok.com → app).'
        }
    };
    <?php else: ?>
    const GUIDES = {};
    <?php endif; ?>

    // Resultado do OAuth: popup entrega via postMessage ao opener; mesmo fluxo
    // (aba original/popup bloqueado) mostra toast + recarrega conexões.
    (function oauthResult() {
        try {
            const p = new URLSearchParams(location.search);
            if (!p.has('oauth')) return;
            const wasPopup = localStorage.getItem('af_oauth_popup') === '1';
            if (wasPopup) localStorage.removeItem('af_oauth_popup');
            const ok = p.get('oauth') === 'ok';
            const msg = p.get('msg') || '';
            if (wasPopup && window.opener) {
                window.opener.postMessage({
                    source: 'af-social-oauth', ok,
                    network: p.get('network') || '', msg,
                }, location.origin);
                document.documentElement.style.visibility = 'hidden';
                setTimeout(() => { try { window.close(); } catch (e) {} }, 200);
                return;
            }
            history.replaceState({}, '', location.pathname);
            document.addEventListener('DOMContentLoaded', () => {
                if (ok) { showToast('Conta conectada com sucesso!', 'success'); loadConnections(); }
                else showToast(msg || 'Falha na conexão.', 'error');
            });
        } catch (e) { /* ignore */ }
    })();

    function openConnModal(n) {
        const m = STATE.networks[n];
        if (!m) return;
        const c = (STATE.connections || []).find(x => x.network === n);
        if (c && c.status === 'connected') return;
        CONN_NET = n;
        // Cliente: sem modal — o clique vai DIRETO para o login da mídia
        // (o próprio popup da Meta mostra qual app está pedindo a conta).
        if (!IS_ADMIN) { startOAuth(); return; }
        const g = GUIDES[n] || { title: m.name, steps: [], token: 'Cole o token de acesso.', creds: '' };

        const el = id => document.getElementById(id);
        el('connTitle').textContent = 'Conectar — ' + (g.title || m.name);
        el('connNetIcon').innerHTML = '<i class="' + m.icon + '"></i>';
        el('connNetIcon').style.background = m.color + '1f';
        el('connNetIcon').style.color = m.color;
        el('connNetDesc').textContent = m.desc || '';
        if (el('connSteps')) el('connSteps').innerHTML = (g.steps || []).map(s => '<li>' + s + '</li>').join('');
        document.querySelectorAll('#connModal .conn-uri').forEach(x => { x.textContent = REDIRECT_URI; });
        if (el('connTokenHint')) el('connTokenHint').textContent = g.token || '';
        if (el('connCredsHelp')) el('connCredsHelp').textContent = g.creds || '';
        if (el('connToken')) el('connToken').value = '';
        if (el('connAppSecret')) el('connAppSecret').value = '';
        el('connMsg').innerHTML = '';
        if (el('connCredsMsg')) el('connCredsMsg').textContent = '';
        if (el('connAppId')) el('connAppId').value = m.app_id || '';
        if (el('connCredsDelBtn')) el('connCredsDelBtn').style.display = m.oauth_source === 'user' ? '' : 'none';
        updateConnOAuthState();
        document.getElementById('connModal').classList.add('active');
    }

    function updateConnOAuthState() {
        const m = STATE.networks[CONN_NET] || {};
        document.getElementById('connOAuthLabel').textContent = 'Entrar com ' + (m.name || '');
        const hint = document.getElementById('connOAuthHint');
        if (!IS_ADMIN) {
            hint.innerHTML = m.oauth_configured
                ? '<i class="fas fa-check" style="color:#28a745;"></i> Clique em <b>Entrar</b> e faça login com sua conta da rede — é só isso, nada para configurar.'
                : '<i class="fas fa-circle-info"></i> Conexão não habilitada nesta plataforma — contate o suporte.';
            return;
        }
        hint.innerHTML = m.oauth_configured
            ? (m.oauth_source === 'user'
                ? '<i class="fas fa-check" style="color:#28a745;"></i> Usa o app salvo na Configuração avançada — clique em <b>Entrar</b>.'
                : '<i class="fas fa-check" style="color:#28a745;"></i> App da plataforma (.env) — clique em <b>Entrar</b> e faça login com usuário/senha da rede (como um SSO).')
            : '<i class="fas fa-circle-info"></i> Sem credenciais — salve App ID/Secret na Configuração avançada abaixo ou preencha o <code>.env</code> da plataforma (veja o guia do dono).';
    }

    // Sem credenciais (só admin): em vez de um botão morto, o clique no Entrar
    // abre a Configuração avançada e foca o App ID — caminho guiado até o login.
    function promptAppCreds() {
        if (!IS_ADMIN) return;
        const box = document.getElementById('connCredsBox');
        if (box) box.open = true;
        const appId = document.getElementById('connAppId');
        if (appId) { try { appId.focus(); } catch (e) {} }
    }

    function closeConnModal() {
        const modal = document.getElementById('connModal');
        if (modal) modal.classList.remove('active');
        CONN_NET = null;
    }

    // Feedback do OAuth: admin vê dentro do modal; cliente (sem modal) leva toast.
    function connSay(html, text, kind) {
        const msg = document.getElementById('connMsg');
        if (msg) { msg.innerHTML = html; return; }
        try { showToast(text, kind || 'info'); } catch (e) {}
    }

    async function startOAuth() {
        const n = CONN_NET;
        if (!n) return;
        const btn = document.getElementById('connOAuthBtn');
        connSay('<i class="fas fa-spinner fa-spin"></i> Abrindo autorização…', 'Abrindo autorização…');
        if (btn) btn.disabled = true;
        let j;
        try {
            j = await api('connect-url', { network: n });
        } finally {
            if (btn) btn.disabled = false;
        }
        if (!j.url) {
            if (j.error === 'oauth_not_configured') promptAppCreds();
            const notCfg = j.error === 'oauth_not_configured';
            const text = notCfg
                ? (IS_ADMIN
                    ? 'Sem credenciais — salve o App ID e o App Secret na Configuração avançada abaixo, ou preencha o .env da plataforma (guia do dono).'
                    : 'Conexão não habilitada nesta plataforma — contate o suporte.')
                : (j.error || 'Não foi possível iniciar.');
            connSay('<span style="color:#dc3545;">' + esc(text) + '</span>', text, 'error');
            return;
        }
        try { localStorage.setItem('af_oauth_popup', '1'); } catch (e) {}
        const w = window.open(j.url, 'af-social-oauth', 'width=580,height=720');
        if (!w) {
            // popup bloqueado → mesmo fluxo antigo (redirect na mesma aba)
            try { localStorage.removeItem('af_oauth_popup'); } catch (e) {}
            window.location.href = j.url;
            return;
        }
        connSay('<i class="fas fa-spinner fa-spin"></i> Conclua a autorização na janela aberta…', 'Conclua a autorização na janela aberta…');
        const res = await waitForOAuthMessage(120000);
        if (!res) {
            const text = 'Janela fechada sem concluir — clique em Conectar novamente.';
            connSay('<span style="color:#dc3545;">' + text + '</span>', text, 'error');
            return;
        }
        if (res.ok) {
            closeConnModal();
            await loadConnections();
            showToast('Conta conectada com sucesso!', 'success');
        } else {
            const text = res.msg || 'Falha na conexão.';
            connSay('<span style="color:#dc3545;">' + esc(text) + '</span>', text, 'error');
        }
    }

    function waitForOAuthMessage(timeoutMs) {
        return new Promise(resolve => {
            let done = false;
            const finish = v => {
                if (done) return;
                done = true;
                clearTimeout(timer);
                window.removeEventListener('message', handler);
                resolve(v);
            };
            const timer = setTimeout(() => finish(null), timeoutMs);
            function handler(e) {
                if (e.origin !== location.origin) return;
                const d = e.data;
                if (!d || d.source !== 'af-social-oauth') return;
                finish(d);
            }
            window.addEventListener('message', handler);
        });
    }

    async function saveManualToken() {
        const n = CONN_NET;
        if (!n) return;
        const token = document.getElementById('connToken').value.trim();
        const msg = document.getElementById('connMsg');
        if (!token) {
            msg.innerHTML = '<span style="color:#dc3545;">Cole o token de acesso.</span>';
            return;
        }
        const btn = document.getElementById('connTokenBtn');
        btn.disabled = true;
        msg.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validando token na API da rede…';
        try {
            const j = await api('save-token', { network: n, token });
            if (j.ok) {
                closeConnModal();
                await loadConnections();
                showToast('Conta conectada com sucesso!', 'success');
            } else {
                msg.innerHTML = '<span style="color:#dc3545;">' + esc(j.error || 'Falha ao validar o token.') + '</span>';
            }
        } finally {
            btn.disabled = false;
        }
    }

    async function saveAppCreds() {
        const n = CONN_NET;
        if (!n) return;
        const appId = document.getElementById('connAppId').value.trim();
        const secret = document.getElementById('connAppSecret').value.trim();
        const m = document.getElementById('connCredsMsg');
        if (!appId || !secret) { m.textContent = 'Preencha App ID e Secret.'; return; }
        const btn = document.getElementById('connCredsSaveBtn');
        btn.disabled = true;
        try {
            const j = await api('app-save', { network: n, app_id: appId, app_secret: secret });
            if (j.ok || j.success) {
                m.textContent = 'Credenciais salvas (secret criptografado).';
                document.getElementById('connAppSecret').value = '';
                document.getElementById('connCredsDelBtn').style.display = '';
                const net = STATE.networks[n] || {};
                net.oauth_configured = true;
                net.oauth_source = 'user';
                net.app_id = appId;
                STATE.networks[n] = net;
                updateConnOAuthState();
            } else {
                m.textContent = j.error || 'Falha ao salvar.';
            }
        } finally {
            btn.disabled = false;
        }
    }

    async function delAppCreds() {
        const n = CONN_NET;
        if (!n) return;
        const m = document.getElementById('connCredsMsg');
        const j = await api('app-delete', { network: n });
        if (j.ok || j.success) {
            m.textContent = 'Credenciais removidas.';
            await loadConnections();
            if (CONN_NET) openConnModal(CONN_NET);
        } else {
            m.textContent = j.error || 'Falha ao remover.';
        }
    }

    async function disconnect(n) {
        if (!confirm('Desconectar esta conta? Publicações agendadas para ela falharão.')) return;
        await api('disconnect', { network: n });
        await loadConnections();
    }

    // ------------------------------------------------------- Fluxos (Fase 3)

    async function loadFlows() {
        const j = await flowApi('list');
        const body = document.getElementById('flowsBody');
        if (j.error) { body.innerHTML = '<tr><td colspan="5" class="empty">' + esc(j.error) + '</td></tr>'; return; }
        STATE.flows = j.flows || [];
        const pill = document.getElementById('flowsPill');
        const on = STATE.flows.filter(f => f.enabled).length;
        pill.innerHTML = '<i class="fas fa-bolt"></i> ' + STATE.flows.length + ' (' + on + ' ativo(s))';
        if (!STATE.flows.length) {
            body.innerHTML = '<tr><td colspan="5" class="empty"><i class="fas fa-bolt"></i> Nenhum fluxo — automatize postagens e webhooks com "Novo fluxo".</td></tr>';
            return;
        }
        body.innerHTML = STATE.flows.map(f => {
            const cfgDays = f.trigger_config.days || [0, 1, 2, 3, 4, 5, 6];
            const trig = f.trigger_kind === 'schedule'
                ? DAY_NAMES.filter((_, i) => cfgDays.includes(i)).join(', ') + ' às ' + esc(f.trigger_config.time || '')
                : TRIG_PT[f.trigger_kind];
            const act = f.action_kind === 'publish_post'
                ? `Publicar → ${(f.action_config.networks || []).map(n => (STATE.networks[n] ? STATE.networks[n].name : n)).join(', ')}`
                : 'POST → ' + esc((f.action_config.url || '').slice(0, 60));
            return `<tr style="border-bottom:1px solid var(--border,#eee);">
                <td style="padding:11px 14px;">
                    ${esc(f.name)}
                    ${f.enabled ? '<span class="pill ok">ativo</span>' : '<span class="pill">pausado</span>'}
                </td>
                <td style="padding:11px 14px;">${esc(trig)}</td>
                <td style="padding:11px 14px;">${act}</td>
                <td style="padding:11px 14px;white-space:nowrap;font-size:.8rem;">
                    ${f.last_run_at ? `<span class="pill ${f.last_status === 'ok' ? 'ok' : 'err'}">${f.last_status === 'ok' ? 'ok' : 'falhou'}</span> ${fmtDate(f.last_run_at)}` : '<span style="color:var(--text-secondary);">nunca</span>'}
                </td>
                <td style="padding:11px 14px;text-align:right;white-space:nowrap;">
                    <button class="btn btn-sm" onclick="runFlow(${f.id})" title="Executar agora"><i class="fas fa-play"></i></button>
                    <button class="btn btn-sm" onclick="toggleFlow(${f.id})" title="${f.enabled ? 'Pausar' : 'Ativar'}"><i class="fas fa-${f.enabled ? 'pause' : 'power-off'}"></i></button>
                    <button class="btn btn-sm" onclick="removeFlow(${f.id})" title="Excluir"><i class="fas fa-trash"></i></button>
                </td>
            </tr>`;
        }).join('');
    }

    function toggleFlowForm() {
        const card = document.getElementById('flowFormCard');
        const isClosed = card.style.display === 'none';
        card.style.display = isClosed ? '' : 'none';
        if (isClosed) {
            renderFlowCfg();
            document.getElementById('flowName').focus();
        }
    }

    function renderFlowCfg() {
        const trig = document.getElementById('flowTrigger').value;
        const act = document.getElementById('flowAction').value;
        const box = document.getElementById('flowCfg');
        let html = '';

        if (trig === 'schedule') {
            const days = [1, 2, 3, 4, 5, 6, 0];
            html += `<div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;">
                <label style="font-size:.82rem;color:var(--text-secondary);">Horário (Brasília)
                    <input type="time" id="flowTime" class="form-control" value="09:00" style="display:block;margin-top:4px;width:150px;">
                </label>
                <div>
                    <div style="font-size:.82rem;color:var(--text-secondary);margin-bottom:4px;">Dias</div>
                    <div style="display:flex;gap:6px;">
                        ${days.map(d => `<label style="font-size:.8rem;"><input type="checkbox" class="flow-day" value="${d}" ${d <= 5 ? 'checked' : ''}> ${DAY_NAMES[d]}</label>`).join('')}
                    </div>
                </div>
            </div>`;
        } else {
            html += `<p style="font-size:.83rem;color:var(--text-secondary);margin:0;">
                <i class="fas fa-circle-info"></i> Dispara assim que um post for publicado nesta conta (publicação manual, agendada ou pelo Sócio).</p>`;
        }

        if (act === 'publish_post') {
            const connected = STATE.connections.filter(c => c.status === 'connected').map(c => c.network);
            html += `<div style="margin-bottom:10px;">
                <div style="font-size:.82rem;color:var(--text-secondary);margin-bottom:6px;">Redes</div>
                <div id="flowNetChecks" style="display:flex;gap:8px;flex-wrap:wrap;">
                    ${connected.length ? connected.map(n => {
                        const m = STATE.networks[n];
                        return `<label class="net-check"><input type="checkbox" class="flow-net" value="${n}"> <i class="${m.icon}" style="color:${m.color};"></i> ${esc(m.name)}</label>`;
                    }).join('') : '<span style="font-size:.83rem;color:var(--text-secondary);">Conecte ao menos uma conta acima.</span>'}
                </div>
            </div>
            <label style="display:block;font-size:.82rem;color:var(--text-secondary);">Legenda
                <textarea id="flowCaption" class="form-control" rows="3" maxlength="64000" placeholder="Texto publicado toda vez que o fluxo rodar" style="width:100%;margin-top:4px;"></textarea>
            </label>`;
        } else {
            html += `<label style="display:block;font-size:.82rem;color:var(--text-secondary);">URL do webhook (POST JSON)
                <input type="url" id="flowWebhookUrl" class="form-control" placeholder="https://seu-n8n/exemplo" style="width:100%;margin-top:4px;"></label>`;
        }

        box.innerHTML = html;
    }

    async function createFlow() {
        const msg = document.getElementById('flowMsg');
        const btn = document.getElementById('flowSaveBtn');
        const trig = document.getElementById('flowTrigger').value;
        const act = document.getElementById('flowAction').value;

        const input = {
            name: document.getElementById('flowName').value.trim(),
            trigger_kind: trig,
            trigger_config: trig === 'schedule'
                ? {
                    time: document.getElementById('flowTime').value,
                    days: Array.from(document.querySelectorAll('.flow-day:checked')).map(c => parseInt(c.value, 10)),
                }
                : {},
            action_kind: act,
            action_config: act === 'publish_post'
                ? {
                    networks: Array.from(document.querySelectorAll('.flow-net:checked')).map(c => c.value),
                    caption: document.getElementById('flowCaption').value,
                }
                : { url: document.getElementById('flowWebhookUrl').value.trim() },
        };

        btn.disabled = true;
        msg.textContent = 'Salvando…';
        try {
            const fd = new FormData();
            fd.append('action', 'create');
            Object.entries(input).forEach(([k, v]) => fd.append(k, JSON.stringify(v)));
            const r = await fetch(FLOWS_API, { method: 'POST', body: fd, credentials: 'same-origin' });
            let j = null;
            try { j = await r.json(); } catch (e) { j = { error: 'Resposta inválida do servidor' }; }
            if (j.ok) {
                msg.textContent = 'Fluxo criado.';
                document.getElementById('flowName').value = '';
                document.getElementById('flowFormCard').style.display = 'none';
                await loadFlows();
            } else {
                msg.textContent = j.error || 'Falha ao criar o fluxo.';
            }
        } finally {
            btn.disabled = false;
            setTimeout(() => { if (msg.textContent.indexOf('criado') === -1) msg.textContent = ''; }, 6000);
        }
    }

    async function toggleFlow(id) {
        const j = await flowApi('toggle', { id });
        if (j.error) { alert(j.error); return; }
        await loadFlows();
    }

    async function removeFlow(id) {
        if (!confirm('Excluir este fluxo e seu histórico de execuções?')) return;
        const j = await flowApi('delete', { id });
        if (j.error) { alert(j.error); return; }
        await loadFlows();
    }

    async function runFlow(id) {
        const pill = document.getElementById('flowsPill');
        pill.innerHTML = '<i class="fas fa-spinner fa-spin"></i> executando…';
        const j = await flowApi('run-now', { id });
        pill.innerHTML = j.ok
            ? '<i class="fas fa-circle" style="color:#28a745;font-size:.6rem;"></i> ' + esc(j.detail || 'executado')
            : '<i class="fas fa-circle" style="color:#dc3545;font-size:.6rem;"></i> ' + esc(j.error || j.detail || 'falhou');
        await loadFlows();
        setTimeout(loadFlows, 4000);
    }

    // Polling de agendamentos vencidos (o cron faz o mesmo no servidor)
    setInterval(async () => {
        if (!document.hasFocus()) return;
        await api('process');
        if (HAS_FEATURE) await flowApi('process');
    }, 60000);

    loadConnections();
    loadFlows();
    </script>
</body>
</html>
