<?php
require_once __DIR__ . '/../lib/Config.php';
require_once Config::getLibDir() . '/Auth.php';
require_once Config::getLibDir() . '/Database.php';
require_once Config::getLibDir() . '/AdSpy/AiConfig.php';
require_once Config::getLibDir() . '/Ai/SttConfig.php';

Auth::requireAuth();
$theme = $_SESSION['theme'] ?? 'light';
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inteligência Artificial - AfiliaFacil</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/theme-light.css">
    <link rel="stylesheet" href="/assets/css/theme-dark.css">
    <link rel="stylesheet" href="/assets/css/app.css">
    <style>
        .tabs { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:20px; border-bottom:1px solid var(--border-color); }
        .tab-btn { padding:10px 18px; border:none; background:none; cursor:pointer; font-size:.9rem; color:var(--text-secondary); border-bottom:2px solid transparent; display:flex; align-items:center; gap:8px; }
        .tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); font-weight:600; }
    </style>
</head>
<body>
    <div class="layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
            <div class="topbar">
                <div class="topbar-title">Inteligência Artificial</div>
                <div class="topbar-actions">
                    <button class="theme-toggle" onclick="toggleTheme()"><i class="fas fa-<?= $theme === 'dark' ? 'sun' : 'moon' ?>"></i></button>
                </div>
            </div>
            <div class="page-content">
                <div class="page-header">
                    <div>
                        <h1>Inteligência Artificial (BYOK)</h1>
                        <p style="color:var(--text-secondary);margin-top:4px;font-size:.9rem;">Use a IA da plataforma (Cloudflare Workers AI) ou conecte seu próprio provider — por capacidade.</p>
                    </div>
                </div>

                <?php if (!AiConfig::isPlatformConfigured()): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> A IA da plataforma ainda não está configurada (CF_AI_TOKEN). Sem uma chave própria (BYOK), as análises IA ficarão indisponíveis.
                </div>
                <?php endif; ?>

                <div class="alert alert-info">
                    <i class="fas fa-infinity"></i> <strong>Com BYOK ativo, as ações de IA (chat, análises, transcrições e narrações) não consomem a cota do plano</strong> — o limite passa a ser o da sua própria chave. Quando a cota da plataforma acabar, configure sua chave e continue sem limite.
                </div>

                <div class="tabs">
                    <button class="tab-btn active" data-tab="chat" onclick="switchTab('chat')"><i class="fas fa-brain"></i> Análise (Chat)</button>
                    <button class="tab-btn" data-tab="stt" onclick="switchTab('stt')"><i class="fas fa-microphone-lines"></i> Transcrição (STT)</button>
                    <button class="tab-btn" data-tab="tts" onclick="switchTab('tts')"><i class="fas fa-volume-high"></i> Narração (TTS)</button>
                    <button class="tab-btn" data-tab="adspy" onclick="switchTab('adspy')"><i class="fas fa-crosshairs"></i> Busca de Anúncios</button>
                </div>

                <div id="tab-chat">
                    <div class="card" style="margin-bottom:24px;">
                        <div class="card-header">
                            <h3><i class="fas fa-robot"></i> Provider de análise (chat)</h3>
                            <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                                <input type="checkbox" id="aiEnabled"> Ativo (usar meu provider)
                            </label>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Usado nas análises de campanha (Ad Spy) e na curadoria IA das Ofertas Escalando.
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Provider <small style="color:var(--text-secondary);">(catálogo models.dev)</small></label>
                                    <select id="aiProvider" class="form-control">
                                        <option value="cloudflare">Cloudflare Workers AI (plataforma)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Modelo</label>
                                    <div style="display:flex;gap:6px;">
                                        <select id="aiModel" class="form-control">
                                            <option value="">Carregando catálogo...</option>
                                        </select>
                                        <button class="btn btn-outline" onclick="refreshLocalModels()" title="Buscar modelos do servidor local"><i class="fas fa-rotate"></i></button>
                                    </div>
                                    <small id="localModelHint" style="color:var(--text-secondary);display:none;font-size:.72rem;margin-top:4px;"></small>
                                    <div id="localModelActions" style="display:none;gap:6px;margin-top:6px;">
                                        <button class="btn btn-outline btn-sm" onclick="loadLocalModel()" title="Carregar o modelo na VRAM"><i class="fas fa-arrow-up"></i> Carregar na VRAM</button>
                                        <button class="btn btn-outline btn-sm" onclick="unloadLocalModel()" title="Descarregar o modelo da VRAM"><i class="fas fa-arrow-down"></i> Descarregar</button>
                                    </div>
                                </div>
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Base URL <small style="color:var(--text-secondary);">(apenas OpenAI-compatible)</small></label>
                                    <input type="text" id="aiBaseUrl" class="form-control" placeholder="https://api.openai.com/v1" oninput="updateBaseUrlHint('aiBaseUrl')">
                                    <small id="aiBaseUrlHint" style="color:var(--text-secondary);display:none;font-size:.72rem;"></small>
                                </div>
                                <div class="form-group">
                                    <label>API Key <small id="keyHint" style="color:var(--text-secondary);"></small></label>
                                    <input type="password" id="aiApiKey" class="form-control" placeholder="deixe vazio para manter">
                                    <small style="color:var(--text-secondary);font-size:.72rem;">Opcional para modelos locais (LM Studio, Ollama)</small>
                                </div>
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Tipo de API <small id="apiTypeSdk" style="color:var(--text-secondary);"></small></label>
                                    <select id="aiApiType" class="form-control" onchange="updateBaseUrlHint('aiBaseUrl')">
                                        <option value="openai">OpenAI-compatible (chat/completions)</option>
                                        <option value="anthropic">Anthropic (messages)</option>
                                        <option value="google">Google (generateContent)</option>
                                        <option value="azure">Azure OpenAI (deployments)</option>
                                        <option value="cloudflare">Cloudflare Workers AI</option>
                                    </select>
                                    <small id="apiTypeHint" style="color:var(--text-secondary);font-size:.72rem;"></small>
                                </div>
                                <div class="form-group">
                                    <label>TTL ocioso (segundos) <small style="color:var(--text-secondary);">(modelos locais)</small></label>
                                    <input type="number" id="aiLocalTtl" class="form-control" min="0" max="86400" placeholder="60">
                                    <small style="color:var(--text-secondary);font-size:.72rem;">Descarrega o modelo da VRAM após este tempo sem uso (0 = padrão do servidor)</small>
                                </div>
                            </div>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button class="btn btn-primary" onclick="saveAi('chat')"><i class="fas fa-save"></i> Salvar</button>
                                <button class="btn btn-outline" onclick="testAi('chat')"><i class="fas fa-plug"></i> Testar conexão</button>
                            </div>
                            <div id="aiTestResult" style="margin-top:12px;"></div>
                        </div>
                    </div>
                </div>

                <div id="tab-stt" style="display:none;">
                    <div class="card" style="margin-bottom:24px;">
                        <div class="card-header">
                            <h3><i class="fas fa-microphone-lines"></i> Provider de transcrição (STT)</h3>
                            <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                                <input type="checkbox" id="sttEnabled"> Ativo (usar meu provider)
                            </label>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Usado em <a href="/admin/transcribe.php"><strong>Transcrições</strong></a> (VSLs, áudios e vídeos).
                                <strong>Deepgram</strong> e <strong>AssemblyAI</strong> aceitam URL de vídeo direto (ideal para VSLs longas); os demais exigem arquivo de áudio (até 24MB).
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Provider</label>
                                    <select id="sttProvider" class="form-control" onchange="updateSttModels()">
                                        <option value="cloudflare">Cloudflare Whisper (plataforma, grátis)</option>
                                        <option value="deepgram">Deepgram (vídeo por URL)</option>
                                        <option value="assemblyai">AssemblyAI (vídeo por URL)</option>
                                        <option value="openai">OpenAI Whisper</option>
                                        <option value="groq">Groq Whisper (rápido/barato)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Modelo</label>
                                    <select id="sttModel" class="form-control"></select>
                                </div>
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Base URL <small style="color:var(--text-secondary);">(apenas OpenAI-compatible)</small></label>
                                    <input type="text" id="sttBaseUrl" class="form-control" placeholder="https://api.openai.com/v1">
                                </div>
                                <div class="form-group">
                                    <label>API Key <small id="sttKeyHint" style="color:var(--text-secondary);"></small></label>
                                    <input type="password" id="sttApiKey" class="form-control" placeholder="deixe vazio para manter">
                                </div>
                            </div>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button class="btn btn-primary" onclick="saveAi('stt')"><i class="fas fa-save"></i> Salvar</button>
                                <button class="btn btn-outline" onclick="testAi('stt')"><i class="fas fa-plug"></i> Testar credenciais</button>
                            </div>
                            <div id="sttTestResult" style="margin-top:12px;"></div>
                        </div>
                    </div>
                </div>
                <div id="tab-tts" style="display:none;">
                    <div class="card" style="margin-bottom:24px;">
                        <div class="card-header">
                            <h3><i class="fas fa-volume-high"></i> Provider de narração (TTS)</h3>
                            <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                                <input type="checkbox" id="ttsEnabled"> Ativo (usar meu provider)
                            </label>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> Usado em <a href="/admin/tts.php"><strong>Narração</strong></a> — geração de áudio a partir de roteiros e textos.
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Provider</label>
                                    <select id="ttsProvider" class="form-control" onchange="updateTtsModels()">
                                        <option value="cloudflare">Cloudflare MeloTTS (plataforma, grátis)</option>
                                        <option value="openai">OpenAI TTS</option>
                                        <option value="elevenlabs">ElevenLabs</option>
                                        <option value="google">Google Gemini TTS</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Modelo</label>
                                    <select id="ttsModel" class="form-control"></select>
                                </div>
                            </div>

                            <div class="grid-2">
                                <div class="form-group">
                                    <label>Base URL <small style="color:var(--text-secondary);">(apenas OpenAI-compatible)</small></label>
                                    <input type="text" id="ttsBaseUrl" class="form-control" placeholder="https://api.openai.com/v1">
                                </div>
                                <div class="form-group">
                                    <label>API Key <small id="ttsKeyHint" style="color:var(--text-secondary);"></small></label>
                                    <input type="password" id="ttsApiKey" class="form-control" placeholder="deixe vazio para manter">
                                </div>
                            </div>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                <button class="btn btn-primary" onclick="saveAi('tts')"><i class="fas fa-save"></i> Salvar</button>
                                <button class="btn btn-outline" onclick="testAi('tts')"><i class="fas fa-plug"></i> Testar credenciais</button>
                            </div>
                            <div id="ttsTestResult" style="margin-top:12px;"></div>
                        </div>
                    </div>
                </div>

                <div id="tab-adspy" style="display:none;">
                    <div class="card" style="margin-bottom:24px;">
                        <div class="card-header"><h3><i class="fas fa-crosshairs"></i> Busca de Anúncios (Ad Spy)</h3></div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-key"></i> Configure <strong>suas chaves</strong> para as buscas usarem a sua conta — sem chave própria, o provedor correspondente fica indisponível (não consumimos a chave da plataforma). Com chave própria, a cota de buscas do plano é liberada.
                            </div>

                            <div style="border:1px solid var(--border-color);border-radius:var(--radius);padding:16px;margin-bottom:16px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <strong><i class="fab fa-google" style="color:#4285f4;"></i> Google Ads Transparency (SerpApi)</strong>
                                    <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                                        <input type="checkbox" id="serpapiEnabled"> Ativo
                                    </label>
                                </div>
                                <p style="font-size:.8rem;color:var(--text-secondary);margin:8px 0;">
                                    Crie sua chave em <a href="https://serpapi.com" target="_blank">serpapi.com</a> — plano gratuito com 250 buscas/mês.
                                </p>
                                <div class="form-group">
                                    <label>SerpApi Key <small id="serpapiKeyHint" style="color:var(--text-secondary);"></small></label>
                                    <input type="password" id="serpapiKey" class="form-control" placeholder="deixe vazio para manter">
                                </div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button class="btn btn-primary btn-sm" onclick="saveAdSpy('adspy_serpapi')"><i class="fas fa-save"></i> Salvar</button>
                                    <button class="btn btn-outline btn-sm" onclick="testAdSpy('adspy_serpapi')"><i class="fas fa-plug"></i> Testar</button>
                                </div>
                                <div id="serpapiResult" style="margin-top:10px;"></div>
                            </div>

                            <div style="border:1px solid var(--border-color);border-radius:var(--radius);padding:16px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                                    <strong><i class="fab fa-facebook" style="color:#1877f2;"></i> Meta Ad Library (opcional)</strong>
                                    <label style="display:flex;align-items:center;gap:6px;font-size:.85rem;font-weight:400;cursor:pointer;">
                                        <input type="checkbox" id="metaEnabled"> Ativo
                                    </label>
                                </div>
                                <p style="font-size:.8rem;color:var(--text-secondary);margin:8px 0;">
                                    Token da API oficial (gratuita) — melhora a estabilidade da busca no Meta. Sem token, usamos a biblioteca pública (gratuita, porém menos estável).
                                </p>
                                <div class="form-group">
                                    <label>Meta Access Token <small id="metaKeyHint" style="color:var(--text-secondary);"></small></label>
                                    <input type="password" id="metaKey" class="form-control" placeholder="deixe vazio para manter">
                                </div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                    <button class="btn btn-primary btn-sm" onclick="saveAdSpy('adspy_meta')"><i class="fas fa-save"></i> Salvar</button>
                                    <button class="btn btn-outline btn-sm" onclick="testAdSpy('adspy_meta')"><i class="fas fa-plug"></i> Testar</button>
                                </div>
                                <div id="metaResult" style="margin-top:10px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="/assets/js/app.js"></script>
    <script>
    let catalog = null;
    const STT_MODELS = {
        cloudflare: [['@cf/openai/whisper-large-v3-turbo', 'Whisper Large v3 Turbo (grátis)'], ['@cf/openai/whisper', 'Whisper (grátis)']],
        deepgram: [['nova-3', 'Nova 3'], ['nova-2', 'Nova 2'], ['whisper-large', 'Whisper Large']],
        assemblyai: [['best', 'Best'], ['nano', 'Nano (mais barato)']],
        openai: [['whisper-1', 'Whisper 1'], ['gpt-4o-transcribe', 'GPT-4o Transcribe'], ['gpt-4o-mini-transcribe', 'GPT-4o Mini Transcribe']],
        groq: [['whisper-large-v3', 'Whisper Large v3'], ['whisper-large-v3-turbo', 'Whisper Large v3 Turbo'], ['distil-whisper-large-v3-en', 'Distil Whisper v3 (EN)']],
    };

    const TTS_MODELS = {
        cloudflare: [['@cf/myshell-ai/melotts', 'MeloTTS (grátis)']],
        openai: [['gpt-4o-mini-tts', 'GPT-4o Mini TTS'], ['tts-1', 'TTS-1'], ['tts-1-hd', 'TTS-1 HD']],
        elevenlabs: [['eleven_multilingual_v2', 'Multilingual v2'], ['eleven_turbo_v2_5', 'Turbo v2.5'], ['eleven_flash_v2_5', 'Flash v2.5']],
        google: [['gemini-2.5-flash-preview-tts', 'Gemini 2.5 Flash TTS'], ['gemini-2.5-pro-preview-tts', 'Gemini 2.5 Pro TTS']],
    };

    function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
        document.getElementById('tab-chat').style.display = tab === 'chat' ? 'block' : 'none';
        document.getElementById('tab-stt').style.display = tab === 'stt' ? 'block' : 'none';
        document.getElementById('tab-tts').style.display = tab === 'tts' ? 'block' : 'none';
        document.getElementById('tab-adspy').style.display = tab === 'adspy' ? 'block' : 'none';
    }

    async function loadCatalog() {
        try {
            let resp = await fetch('https://models.dev/api.json').catch(() => null);
            if (!resp || !resp.ok) {
                resp = await fetch('/proxy.php?url=' + encodeURIComponent('https://models.dev/api.json'));
            }
            catalog = await resp.json();
        } catch (e) {
            catalog = null;
        }
        populateProviders();
    }

    function populateProviders() {
        const select = document.getElementById('aiProvider');
        select.innerHTML = '<option value="cloudflare">Cloudflare Workers AI (plataforma)</option>';

        if (catalog) {
            const preferred = ['openai', 'anthropic', 'google', 'openrouter', 'groq', 'deepseek', 'mistral', 'xai'];
            const ids = Object.keys(catalog).sort((a, b) => {
                const ia = preferred.indexOf(a), ib = preferred.indexOf(b);
                if (ia !== -1 || ib !== -1) return (ia === -1 ? 99 : ia) - (ib === -1 ? 99 : ib);
                return a.localeCompare(b);
            });
            ids.forEach(id => {
                const provider = catalog[id];
                if (!provider || !provider.models) return;
                const opt = document.createElement('option');
                opt.value = id;
                opt.textContent = (provider.name || id) + ' — ' + Object.keys(provider.models).length + ' modelos';
                select.appendChild(opt);
            });
        }
        updateModels();
    }

    // Base URLs conhecidas como fallback (quando o provider nao esta no catalogo models.dev)
    const FALLBACK_URLS = {
        openai: 'https://api.openai.com/v1',
        anthropic: 'https://api.anthropic.com/v1',
        google: 'https://generativelanguage.googleapis.com/v1beta',
        openrouter: 'https://openrouter.ai/api/v1',
        groq: 'https://api.groq.com/openai/v1',
        deepseek: 'https://api.deepseek.com/v1',
        mistral: 'https://api.mistral.ai/v1',
        xai: 'https://api.x.ai/v1',
        deepgram: 'https://api.deepgram.com/v1',
        assemblyai: 'https://api.assemblyai.com/v2',
        elevenlabs: 'https://api.elevenlabs.io/v1',
        lmstudio: 'http://127.0.0.1:1234/v1',
        ollama: 'http://127.0.0.1:11434/v1',
    };

    // App rodando em Docker? (o localhost do host nao e alcancavel de dentro do container)
    const IN_DOCKER = <?= getenv('DOCKER') ? 'true' : 'false' ?>;

    /**
     * Sugere a Base URL conforme o provider (campo models.dev "api", com fallback local).
     * O campo continua editavel: nunca sobrescreve um valor digitado pelo usuario.
     */
    function suggestBaseUrl(inputId, providerId) {
        const input = document.getElementById(inputId);
        if (!input) return;

        const fromCatalog = (catalog && catalog[providerId] && catalog[providerId].api) ? catalog[providerId].api : '';
        const suggested = fromCatalog || FALLBACK_URLS[providerId] || '';

        const prev = input.dataset.suggested || '';
        input.placeholder = suggested || 'https://api.openai.com/v1';

        // Preenche se vazio ou se ainda contem a sugestao anterior (nao sobrescreve valor manual)
        if (input.value === '' || input.value === prev) {
            input.value = suggested;
        }

        input.dataset.suggested = suggested;
        updateBaseUrlHint(inputId);
    }

    /**
     * Deriva o tipo de API do SDK do provider (models.dev "npm") — o campo continua editavel.
     */
    function updateApiType(providerId) {
        const select = document.getElementById('aiApiType');
        const sdkLabel = document.getElementById('apiTypeSdk');
        const hint = document.getElementById('apiTypeHint');
        if (!select) return;

        if (providerId === 'cloudflare') {
            select.value = 'cloudflare';
            sdkLabel.textContent = '(plataforma)';
            hint.textContent = '';
            return;
        }

        const npm = (catalog && catalog[providerId] && catalog[providerId].npm) ? catalog[providerId].npm : '';

        let type = 'openai';
        if (npm.includes('anthropic')) type = 'anthropic';
        else if (npm.includes('google')) type = 'google';
        else if (npm.includes('azure')) type = 'azure';

        const unsupported = npm && (npm.includes('bedrock') || npm.includes('vertex'));
        if (unsupported) {
            hint.textContent = '⚠ SDK ' + npm + ' não suportado — use um gateway OpenAI-compatible ou ajuste o tipo manualmente.';
        } else if (npm) {
            hint.textContent = 'Detectado do SDK ' + npm + ' — ajuste se necessário.';
        } else {
            hint.textContent = '';
        }

        select.value = type;
        sdkLabel.textContent = npm ? '(' + npm + ')' : '';
    }

    /**
     * Mostra dica quando a URL aponta para um servidor local (chave opcional / fallback automatico).
     */
    function updateBaseUrlHint(inputId) {
        const input = document.getElementById(inputId);
        const hint = document.getElementById(inputId + 'Hint');
        if (!input || !hint) return;

        const url = (input.value || '').trim();
        const isLocal = /\/\/(127\.0\.0\.1|localhost|0\.0\.0\.0|host\.docker\.internal)/.test(url);

        if (!isLocal) {
            hint.style.display = 'none';
            return;
        }

        hint.style.display = 'block';
        hint.textContent = 'Servidor local — a API Key é opcional. A conexão testa automaticamente 127.0.0.1/localhost/host.docker.internal (funciona no host e no Docker).';
    }

    // ------------------------------------------------------------------
    // Modelos locais (VRAM): listar com estado, carregar/descarregar
    // ------------------------------------------------------------------

    let localModelsCache = [];

    function isLocalProvider(providerId) {
        if (providerId === 'lmstudio' || providerId === 'ollama') return true;
        const api = (catalog && catalog[providerId] && catalog[providerId].api) ? catalog[providerId].api : (FALLBACK_URLS[providerId] || '');
        return /\/\/(127\.0\.0\.1|localhost|0\.0\.0\.0)/.test(api);
    }

    function hideLocalModelControls() {
        const hint = document.getElementById('localModelHint');
        const actions = document.getElementById('localModelActions');
        if (hint) hint.style.display = 'none';
        if (actions) actions.style.display = 'none';
        localModelsCache = [];
    }

    function updateLocalModelState() {
        const hint = document.getElementById('localModelHint');
        const actions = document.getElementById('localModelActions');
        if (!hint || !actions) return;

        const loaded = localModelsCache.filter(m => m.state === 'loaded');
        hint.style.display = 'block';
        hint.textContent = localModelsCache.length + ' modelos no servidor local' +
            (loaded.length ? ' — na VRAM: ' + loaded.map(m => m.id).join(', ') : ' — nenhum carregado na VRAM');
        actions.style.display = 'flex';
    }

    async function refreshLocalModels() {
        const baseUrl = document.getElementById('aiBaseUrl').value.trim();
        const hint = document.getElementById('localModelHint');
        if (!hint) return;

        if (!baseUrl) {
            hint.style.display = 'block';
            hint.textContent = 'Informe a Base URL do servidor local para listar os modelos.';
            return;
        }

        hint.style.display = 'block';
        hint.textContent = 'Buscando modelos do servidor local...';

        try {
            const resp = await fetch('/admin/api/ai-settings.php?action=local-models&base_url=' + encodeURIComponent(baseUrl));
            const data = await resp.json();

            if (!data.ok) {
                hint.textContent = '⚠ ' + (data.error || 'Falha ao listar os modelos do servidor local');
                document.getElementById('localModelActions').style.display = 'none';
                return;
            }

            localModelsCache = data.models || [];
            const select = document.getElementById('aiModel');
            const current = select.value;
            select.innerHTML = '';
            localModelsCache.forEach(m => {
                const label = m.id + (m.state === 'loaded' ? '  ✓ carregado' : '') + (m.quantization ? '  [' + m.quantization + ']' : '');
                select.appendChild(new Option(label, m.id));
            });
            if (current && localModelsCache.some(m => m.id === current)) select.value = current;

            updateLocalModelState();
        } catch (e) {
            hint.textContent = '⚠ Falha ao conectar no servidor local';
        }
    }

    async function loadLocalModel() {
        const baseUrl = document.getElementById('aiBaseUrl').value.trim();
        const model = document.getElementById('aiModel').value;
        if (!model) { showToast('Selecione um modelo', 'error'); return; }

        const hint = document.getElementById('localModelHint');
        hint.textContent = 'Carregando ' + model + ' na VRAM (pode demorar)...';

        const body = new URLSearchParams({ action: 'local-load', base_url: baseUrl, model });
        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();

        showToast(data.message || data.error || 'Falha', data.ok ? 'success' : 'error');
        await refreshLocalModels();
    }

    async function unloadLocalModel() {
        const baseUrl = document.getElementById('aiBaseUrl').value.trim();
        const model = document.getElementById('aiModel').value;
        if (!model) { showToast('Selecione um modelo', 'error'); return; }

        const body = new URLSearchParams({ action: 'local-unload', base_url: baseUrl, model });
        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();

        showToast(data.message || data.error || 'Falha', data.ok ? 'success' : 'error');
        await refreshLocalModels();
    }

    function updateModels() {
        const providerId = document.getElementById('aiProvider').value;
        suggestBaseUrl('aiBaseUrl', providerId);
        updateApiType(providerId);
        const modelSelect = document.getElementById('aiModel');
        modelSelect.innerHTML = '';

        // Provider local: lista os modelos DO SERVIDOR (com estado na VRAM)
        if (isLocalProvider(providerId)) {
            refreshLocalModels();
            return;
        }

        hideLocalModelControls();

        if (providerId === 'cloudflare') {
                        [['@cf/nvidia/nemotron-3-120b-a12b', 'Nemotron 3 120B (grátis — rápido, recomendado)'], ['@cf/zai-org/glm-4.7-flash', 'GLM 4.7 Flash (grátis — mais lento)'], ['@cf/google/gemma-4-26b-a4b-it', 'Gemma 4 26B (grátis — lento)']]
                .forEach(([v, l]) => modelSelect.appendChild(new Option(l, v)));
            return;
        }

        if (catalog && catalog[providerId] && catalog[providerId].models) {
            const models = Object.keys(catalog[providerId].models).sort();
            models.forEach(m => modelSelect.appendChild(new Option(m, m)));
        } else {
            modelSelect.appendChild(new Option('(digite manualmente abaixo)', ''));
        }
    }

    function updateSttModels() {
        const providerId = document.getElementById('sttProvider').value;
        suggestBaseUrl('sttBaseUrl', providerId);
        const modelSelect = document.getElementById('sttModel');
        modelSelect.innerHTML = '';
        (STT_MODELS[providerId] || []).forEach(([v, l]) => modelSelect.appendChild(new Option(l, v)));
    }

    function updateTtsModels() {
        const providerId = document.getElementById('ttsProvider').value;
        suggestBaseUrl('ttsBaseUrl', providerId);
        const modelSelect = document.getElementById('ttsModel');
        modelSelect.innerHTML = '';
        (TTS_MODELS[providerId] || []).forEach(([v, l]) => modelSelect.appendChild(new Option(l, v)));
    }

    async function loadConfig(capability) {
        const resp = await fetch('/admin/api/ai-settings.php?action=get&capability=' + capability);
        const data = await resp.json();
        if (!data.success) return;

        if (capability === 'chat') {
            const c = data.config;
            document.getElementById('aiEnabled').checked = c.enabled;
            document.getElementById('aiBaseUrl').value = c.base_url;
            document.getElementById('aiLocalTtl').value = c.local_ttl || '';
            document.getElementById('keyHint').textContent = c.has_key ? '(configurada - vazio mantém)' : '';
            if (c.provider && c.provider !== 'cloudflare') {
                document.getElementById('aiProvider').value = c.provider;
                updateModels();
                // Respeita o tipo de API salvo (nao sobrescreve com o detectado)
                if (c.api_type) document.getElementById('aiApiType').value = c.api_type;
                if (c.model) {
                    const opt = new Option(c.model, c.model);
                    document.getElementById('aiModel').appendChild(opt);
                    document.getElementById('aiModel').value = c.model;
                }
            }
            return;
        }

        if (capability === 'stt') {
            const c = data.config;
            document.getElementById('sttEnabled').checked = c.enabled;
            document.getElementById('sttBaseUrl').value = c.base_url;
            document.getElementById('sttKeyHint').textContent = c.has_key ? '(configurada — vazio mantém)' : '';
            document.getElementById('sttProvider').value = c.provider || 'cloudflare';
            updateSttModels();
            if (c.model) {
                const opt = new Option(c.model, c.model);
                document.getElementById('sttModel').appendChild(opt);
                document.getElementById('sttModel').value = c.model;
            }
            return;
        }

        if (capability === 'tts') {
            const c = data.config;
            document.getElementById('ttsEnabled').checked = c.enabled;
            document.getElementById('ttsBaseUrl').value = c.base_url;
            document.getElementById('ttsKeyHint').textContent = c.has_key ? '(configurada — vazio mantém)' : '';
            document.getElementById('ttsProvider').value = c.provider || 'cloudflare';
            updateTtsModels();
            if (c.model) {
                const opt = new Option(c.model, c.model);
                document.getElementById('ttsModel').appendChild(opt);
                document.getElementById('ttsModel').value = c.model;
            }
        }
    }

    async function saveAi(capability) {
        const body = new URLSearchParams();
        body.append('action', 'save');
        body.append('capability', capability);

        if (capability === 'chat') {
            body.append('provider', document.getElementById('aiProvider').value);
            body.append('api_type', document.getElementById('aiApiType').value);
            body.append('model', document.getElementById('aiModel').value);
            body.append('base_url', document.getElementById('aiBaseUrl').value);
            body.append('local_ttl', document.getElementById('aiLocalTtl').value || '0');
            body.append('api_key', document.getElementById('aiApiKey').value);
            body.append('enabled', document.getElementById('aiEnabled').checked ? '1' : '0');
        } else if (capability === 'stt') {
            body.append('provider', document.getElementById('sttProvider').value);
            body.append('model', document.getElementById('sttModel').value);
            body.append('base_url', document.getElementById('sttBaseUrl').value);
            body.append('api_key', document.getElementById('sttApiKey').value);
            body.append('enabled', document.getElementById('sttEnabled').checked ? '1' : '0');
        } else {
            body.append('provider', document.getElementById('ttsProvider').value);
            body.append('model', document.getElementById('ttsModel').value);
            body.append('base_url', document.getElementById('ttsBaseUrl').value);
            body.append('api_key', document.getElementById('ttsApiKey').value);
            body.append('enabled', document.getElementById('ttsEnabled').checked ? '1' : '0');
        }

        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) { showToast('Configuração salva!', 'success'); loadConfig(capability); }
        else showToast(data.error || 'Erro ao salvar', 'error');
    }

    async function testAi(capability) {
        const el = document.getElementById(capability === 'chat' ? 'aiTestResult' : capability === 'stt' ? 'sttTestResult' : 'ttsTestResult');
        el.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';
        const body = new URLSearchParams({ action: 'test', capability });
        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();
        el.innerHTML = data.ok
            ? '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' + esc(data.message) + '</div>'
            : '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' + esc(data.error || 'Falha') + '</div>';
    }

    document.getElementById('aiProvider').addEventListener('change', updateModels);
    updateSttModels();
    updateTtsModels();

    async function loadAdSpy(cap) {
        const prefix = cap === 'adspy_serpapi' ? 'serpapi' : 'meta';
        const resp = await fetch('/admin/api/ai-settings.php?action=get&capability=' + cap);
        const data = await resp.json();
        if (!data.success) return;
        document.getElementById(prefix + 'Enabled').checked = !!data.config.enabled;
        document.getElementById(prefix + 'KeyHint').textContent = data.config.has_key ? '(configurada — vazio mantém)' : '';
    }

    async function saveAdSpy(cap) {
        const prefix = cap === 'adspy_serpapi' ? 'serpapi' : 'meta';
        const body = new URLSearchParams();
        body.append('action', 'save');
        body.append('capability', cap);
        body.append('provider', cap === 'adspy_serpapi' ? 'serpapi' : 'meta');
        body.append('api_key', document.getElementById(prefix + 'Key').value);
        body.append('enabled', document.getElementById(prefix + 'Enabled').checked ? '1' : '0');

        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();
        if (data.success) {
            showToast('Chave salva', 'success');
            document.getElementById(prefix + 'Key').value = '';
            loadAdSpy(cap);
        } else {
            showToast(data.error || 'Erro ao salvar', 'error');
        }
    }

    async function testAdSpy(cap) {
        const prefix = cap === 'adspy_serpapi' ? 'serpapi' : 'meta';
        const el = document.getElementById(prefix + 'Result');
        el.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Testando...';
        const body = new URLSearchParams({ action: 'test', capability: cap });
        const resp = await fetch('/admin/api/ai-settings.php', { method: 'POST', body });
        const data = await resp.json();
        el.innerHTML = data.ok
            ? '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' + esc(data.message) + '</div>'
            : '<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> ' + esc(data.error || 'Falha') + '</div>';
    }

    loadCatalog().then(() => {
        loadConfig('chat');
        loadConfig('stt');
        loadConfig('tts');
        loadAdSpy('adspy_serpapi');
        loadAdSpy('adspy_meta');
    });
    </script>
</body>
</html>
