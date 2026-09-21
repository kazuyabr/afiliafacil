# AfiliaFacil

Plataforma completa para afiliados: clonador de páginas, pressel, player de vídeo, pixel, back redirect, cookie de afiliado, domínios, integrações e assinaturas com PIX/Stripe. Concorrente direto da Afiliaze (afiliaze.com.br).

## Stack

- **PHP 8.2** (sem framework) + **Composer** (Eloquent ORM + Phinx migrations)
- **Docker** — built-in PHP server, porta **9876** (única do projeto; verificar antes se está livre: `netstat -an | Select-String ":9876"`)
- **PostgreSQL 16** (container `afiliafacil-db`) — default; MySQL suportado via `DB_CONNECTION=mysql`. Sem banco configurado → fallback JSON em `data/` (compatibilidade)
- Páginas geradas em `pages/` (HTML em arquivo, metadados no banco), uploads em `uploads/`

## Banco de dados (Eloquent + Phinx)

- Config: `lib/Database.php` (env `DB_CONNECTION`/`DB_HOST`/... ou `DATABASE_URL`)
- Migrations: `database/migrations/` (Phinx, `phinx.php`); rodam automaticamente no boot do container via `bin/migrate.php`
- Import JSON→DB idempotente: `lib/ImportJsonData.php` (roles seed, planos/preços seed, users/pages/payments/settings)
- Models: `lib/Models/` (namespace `AfiliaFacil\Models`): User, Role, Plan, PlanPrice, Payment, Setting, Page, StorageConfig
- Rodar manualmente: `docker exec afiliafacil php bin/migrate.php`
- Tabelas: roles, users (role_id), plans, plan_prices, payments, settings, pages (HTML em arquivo), storage_configs (R2 por usuário)
- Fallback JSON: `Auth`/`PageManager`/`Payments`/`Settings` funcionam sem DB (arquivos em `data/`)

## RBAC e painel Master

- **Cargos** (tabela `roles`): master (protegido, todas as permissões), admin, gerente, afiliado + CRUD de cargos custom em `/admin/roles.php`
- **Permissões**: `manage_users`, `manage_roles`, `manage_pricing`, `manage_pages`, `manage_settings`, `manage_payments`, `manage_storage` — checadas via `Auth::can()` (sidebar e endpoints)
- **Master** (`admin@afiliafacil.com`): não pode ser excluído, desativado nem ter cargo alterado (guards em `admin/api/users.php`)
- **Preços dinâmicos**: `/admin/pricing.php` (master/admin) edita nome/label/limites/features/preços por ciclo; `Plans::all()` lê do DB (cache estático, `Plans::refresh()` após edição); landing e checkouts refletem na hora
- **Seed não destrutivo**: `ImportJsonData::seedPlans/seedRoles` usam `firstOrCreate` — edições do admin NUNCA são sobrescritas no boot
- **Trial dinâmico**: `trial_days` em settings; landing/registro leem de `Settings::get('trial_days')`

## CRON (preparação Plano B — Ofertas Escalando)

- `CRON_KEY` no `.env` (gerar com `openssl rand -hex 32`) — endpoint futuro `/cron/monitor.php?key=...` (monitoramento de ofertas, modo `cron` | `manual` configurável). Documentado no `.env.sample`.

## Armazenamento R2 por usuário

- `/admin/storage.php`: cada usuário configura seu Cloudflare R2 (Account ID, Access Key, Secret, Bucket, Public URL/CDN)
- **Secret criptografado** com AES-256-GCM (`lib/Crypto.php`, chave de `APP_KEY` env ou gerada em settings)
- **media_mode** por usuário: `base64` (default) | `r2` (upload no bucket → URL CDN) | `original` (mantém URL da origem via proxy — clone leve, sem baixar assets)
- `lib/R2Storage.php`: SigV4 via curl (upload/delete/deletePrefix/testConnection) — sem SDK
- Clonagem usa o storage do dono (`api/clone.php` → `loadUserStorage` → `Cloner::process(..., $storageConfig, 'clones/<pageId>')` → `AssetProcessor::downloadAsset`)
- **"Otimizar mídias (R2)"** na edição da página: converte data URIs existentes → R2 (`lib/MediaOptimizer.php`, cria revisão antes)
- Delete da página remove `clones/<pageId>/` no R2 (`api/pages.php`)

- **Tempo real nas tabelas**: Minhas Páginas e Pagamentos (admin) fazem polling de 15s (fetch + DOMParser substitui o tbody se mudou) + refresh ao voltar para a aba (`visibilitychange`) + ações otimistas (delete remove a linha com fade, sem F5)

## Segurança

- **2FA TOTP** (`lib/Totp.php`, RFC 6238 — validado com vetores oficiais): ativação em Admin → Configurações → Segurança (QR via `api.qrserver.com` + chave manual + 10 códigos de recuperação exibidos uma única vez, single-use, hash `password_hash`). Login em 2 etapas (`index.php`: etapa 2 com sessão pendente `pending_2fa_user_id`; código TOTP ou recovery code). Secret criptografado com AES-256-GCM (`Crypto`). API: `admin/api/2fa.php` (status/setup/confirm/disable/regenerate). Rota `/admin/api/2fa.php` no router. Desativar/regerar exige código atual; auditoria (`2fa_enabled`, `2fa_disabled`, `2fa_recovery_used`, `login_2fa_pending`, `login_2fa_failed`).
- **Contas ADMIN**: criadas/gerenciadas apenas por quem tem cargo master/admin (`admin/api/users.php` → `isAdminRoleId()`). Não-admin com `manage_users` (ex.: gerente custom) NÃO pode criar/editar/excluir/desativar contas admin/master (403) nem atribuir esses cargos; o select de cargos some com master/admin (`admin/users.php` → `actorIsAdmin` + `canManage`). Atribuir cargo admin força plano `premium`; rebaixar volta para `trial`. Master só é editável por ele mesmo; não pode ser excluído/desativado nem ter cargo alterado (403).
- **`/admin/plan.php` para admins**: conta admin vê card "ADMIN — Conta de Trabalho" (acesso total) e os planos comerciais atenuados/desabilitados ("Disponível para clientes"), sem botão de upgrade/checkout.
- **Isolamento de dados (app)**: `Auth::canAccessPage()`/`requirePageAccess()` — páginas só são acessíveis pelo dono ou admin (aplicado em pages/preview/download/editor/APIs); listagem e stats filtradas por usuário (`PageManager::listByUser`)
- **Roles Postgres (least privilege)**: runtime conecta com `afiliafacil_app` (sem DDL — só DML + sequences); migrações usam o owner via `DB_MIGRATION_USER`/`DB_MIGRATION_PASSWORD`. A role é criada/atualizada no boot por `bin/migrate.php` (`DB_APP_USER`/`DB_APP_PASSWORD`)
- **Rate limiting no login**: 5 falhas/15min por e-mail → bloqueio temporário (tabela `login_attempts`); mensagem exibida no login
- **Auditoria**: tabela `audit_log` + `Audit::log()` — registra login/ok/falha/bloqueio, mudanças de plano, edição de preços, exclusão de página, CRUD de usuários/cargos e storage; tela `/admin/audit.php` (permissão `manage_settings`)
- **Produção**: use `DATABASE_URL` com `sslmode=require` e senhas fortes via secrets (nunca os defaults de dev)
- RLS não implementado (decisão de escopo — isolamento é feito na app + role restrita)

## Espionagem de Anúncios (Ad Spy)

- **Módulo** (`lib/AdSpy/`): busca centralizada nas bibliotecas públicas — **Meta Ad Library** (scraping dos endpoints internos; API oficial via token do usuário), **Google Ads Transparency** (via **SerpApi**), **TikTok Creative Center** (scraping).
- **BYOK de busca** (`AdSpyKeys`, capabilities `adspy_serpapi`/`adspy_meta` em `user_ai_configs`): o usuário configura as próprias chaves em **IA → Busca de Anúncios**.
  - **Usuário sem chave própria → provedor bloqueado com aviso** (não gastamos a chave da plataforma); **com chave própria → quota de buscas liberada** (`source: byok`).
  - `SERPAPI_KEY`/`META_AD_ACCESS_TOKEN` do `.env` são usados **somente pelo sistema/cron** (`AdSpyManager::searchSystem`, user_id 0) — ex.: coleta de ofertas.
  - Meta sem token usa a biblioteca pública (gratuita); TikTok é sempre scraping gratuito.
- **Dossiê da Campanha**: botão "Espionar Campanha" na edição da página clonada → extrai sinais (domínio, marca, termos, checkouts) → busca nas 3 plataformas → **análise IA** (resumo, ângulos, oferta, público, funil, termos para testar, sugestões).
- **Cache 24h** (`ad_spy_cache`) — busca repetida NÃO consome quota; providers que falham NÃO consomem quota.
- **Quotas por plano** (`plans.max_adspy_searches` / `max_ai_analyses`, editáveis em `/admin/pricing.php`): Trial 3/3 · VSL Start 0/0 · Afiliado Pro 30/10 · Master Elite 300/100 · Admin ilimitado. A quota de **análise IA** também é liberada com BYOK de chat.
- **Planos**: Teste Grátis (trial) · VSL Start · Afiliado Pro · Master Elite.
- **IA**: padrão **Cloudflare Workers AI** (`CF_ACCOUNT_ID`/`CF_AI_TOKEN`/`CF_AI_MODEL`) — modelo padrão **`@cf/nvidia/nemotron-3-120b-a12b`** (3-5s/resposta, tool_calls corretos nos testes); alternativas grátis na UI: GLM 4.7 Flash (47s+) e Gemma 4 26B (89s+). + **BYOK** em `/admin/ai-settings.php` (catálogo **models.dev** via JS; suporta cloudflare/openai-compatible/anthropic/google; chave criptografada AES).
- **Páginas**: `/admin/adspy.php` (busca + grid + filtros por plataforma), `/admin/ai-settings.php` (BYOK); APIs `api/adspy.php` (quota/search/dossier/analyze) e `api/ai-settings.php` (get/save/test por capability, incluindo as chaves de busca).
- **Robustez da IA**: `AiClient::request` com timeout de **180s**; `AiClient::cloudflare` normaliza `result.response`/`choices[0].message.content` (inclusive conteúdo em array de blocos). O **comentário de resultado** usa `AgentPrompts::comment()` (prompt CURTO ~800 chars com few-shot do swipe vazio) — o prompt completo (8k) estourava o timeout de 90s. `handleToolCall` é reutilizado pelo `handleResponse` e pelo `commentOnResult` (a IA às vezes responde tool_call quando deveria comentar) com profundidade máxima 3 (anti-loop). Regra de **pedido explícito**: se o usuário pedir uma ação específica (ex.: "espione anúncios de X"), a IA executa exatamente o pedido — a busca de ofertas do onboarding vale só quando ele apenas informa o nicho.
- **Limite da conta CF**: a cota gratuita é de **10.000 neurons/dia** (compartilhada por todas as chamadas da plataforma). Ao estourar, a API retorna 429 — `AiClient::lastError()`/`isQuotaError()` detectam e o Sócio responde orientando o **BYOK** (`IA → Configurações → aba Análise`), sem retries inúteis. Uso intenso de testes consome a cota — renova diariamente.
- **Fallback de cotas** (`AiConfig::candidates` + `AiClient::chatWithFallback`): a ordem é **BYOK do usuário → chave principal da plataforma → chave alternativa da plataforma** (`CF_AI_TOKEN_2`/`CF_ACCOUNT_ID_2`). Quando uma cota/limite estoura, o sistema tenta a próxima automaticamente — o admin pode somar cotas com uma segunda conta Cloudflare (gratuita) ou usar a própria chave (BYOK). Aplicado no Sócio, subagentes, análise de ofertas e dossiê do Ad Spy. Mensagem de cota do admin cita a chave alternativa.

## Ofertas Escalando (swipe file)

- **Módulo** (`lib/Offers/`): swipe file de ofertas validadas nas bibliotecas de anúncios com métricas de escala (histórico diário → sparkline), criativos e páginas (thumbnails, sem screenshots).
- **Coleta** (`OfferCollector`): busca termos configurados nos providers do Ad Spy (via `AdSpyManager::searchSystem` — NÃO consome quota de usuário) e agrupa anúncios por domínio/anunciante → `offers` + `offer_creatives` + `offer_pages` + `offer_metrics`. Anúncios de checkout/hotmart etc. classificados como página `checkout`.
- **IA** (`OfferAi`): analisa cada oferta (nicho, estrutura vsl/quiz/low_ticket/infoproduto/carta, idioma, score 0-100, resumo, ângulos, sugestões) — usa Cloudflare Workers AI da plataforma ou BYOK. `analyzePending()` roda em lote; **auto-aprovação** quando `offers_auto_approve=1` e score >= `offers_auto_approve_score` (curadoria com mínimo trabalho do admin).
- **Monitor/cron**: `/cron/monitor.php?key=CRON_KEY` (rota no router) — atualiza métricas das ofertas, coleta novas e roda IA nas pendentes. Chave: `CRON_KEY` (env) ou `cron_key` em settings (gerada/rotacionada no painel). Modo `offers_monitor_mode`: `cron` (padrão) | `manual` (endpoint retorna `skipped`).
- **Quotas por plano** (`plans.max_offers_views`, editáveis no pricing): Trial 3 · VSL Start 0 · Afiliado Pro 30 · Master Elite 300 · Admin ilimitado. Ver o dossiê consome 1 view/mês por oferta; reabrir a mesma oferta no mês NÃO consome (dedupe em `offer_views`).
- **Feature**: `offers` (trial, essencial, master, premium) — gating em `admin/ofertas.php`, sidebar e `api/ofertas.php`.
- **UI**: `/admin/ofertas.php` com abas Ofertas (filtros nicho/estrutura/tráfego/idioma/ordenação + cards com sparkline e badge de escala), Criativos, Páginas e **Curadoria** (só admin: stats, aprovação/rejeição em lote, análise IA, configurações do monitor, CRON_KEY mascarada + comando cron, botões "Buscar agora"/"Atualizar métricas").
- **Ações integradas**: "Clonar" abre `/admin/clone.php?url=&name=` (prefill); "Espionar" abre `/admin/adspy.php?query=` (prefill + auto-search).
- **API**: `admin/api/ofertas.php` (list/get/creatives/pages/approve/reject/bulk/analyze/analyze-pending/collect/monitor/settings/cron-key) — ações de admin exigem `isAdmin` (403).
- **Tabelas**: `offers`, `offer_metrics`, `offer_creatives`, `offer_pages`, `offer_suggestions`, `offer_views` (migrations 14 e 15).

## Transcrições (STT)

- **Módulo** (`lib/Ai/`): transcrição de VSLs, áudios e vídeos com timestamps — `SttClient` (5 providers), `SttConfig` (BYOK por capacidade), `SttQuota`, `MediaDetector`.
- **Providers**: **Cloudflare Whisper** (padrão grátis da plataforma, aceita áudio até 24MB), **OpenAI/Groq** (áudio até 24MB), **Deepgram/AssemblyAI** (aceitam **URL de vídeo direto** — ideais para VSLs longas). Sem ffmpeg no container (decisão): VSLs longas via Deepgram/AssemblyAI.
- **BYOK por capacidade**: `user_ai_configs.capability` (`chat` | `stt`) com unique composto (user_id, capability) — configs independentes. UI em `/admin/ai-settings.php` com abas Análise (Chat) e Transcrição (STT); o teste de STT valida credenciais de verdade (endpoint leve por provider).
- **Fluxo**: `/admin/transcribe.php` — URL (com **detecção automática de mídia** na página: `<video>`, `og:video`, extensões diretas; players embedados são avisados) ou upload de arquivo; resultado com copiar/TXT/SRT (gerado no browser a partir das words); histórico por usuário.
- **Quotas por plano** (`plans.max_transcriptions`): Trial 2 · VSL Start 0 · Afiliado Pro 10 · Master Elite 100 · Admin ilimitado. Só transcrições **concluídas** consomem quota (falhas não).
- **API**: `admin/api/transcribe.php` (quota/list/get/delete/detect/transcribe) e `admin/api/ai-settings.php` (get/save/test com `capability`).
- **Integração**: botão "Transcrever VSL" no dossiê das Ofertas Escalando (`/admin/transcribe.php?url=`).
- **Upload**: php.ini do container com `upload_max_filesize=24M`, `post_max_size=26M`, `max_execution_time=300` (Dockerfile).
- **Tabela**: `transcriptions` (migration 16-17).

## Narração (TTS)

- **Módulo** (`lib/Ai/TtsClient.php` + `TtsConfig` + `TtsQuota`): geração de áudio a partir de texto (roteiros/VSLs) — 4 providers: **Cloudflare MeloTTS** (padrão grátis da plataforma), **OpenAI TTS** (gpt-4o-mini-tts/tts-1/tts-1-hd), **ElevenLabs** (multilingual v2/turbo/flash) e **Google Gemini TTS** (PCM convertido para WAV no cliente).
- **BYOK por capacidade**: `user_ai_configs.capability = 'tts'`; UI em `/admin/ai-settings.php` aba Narração; teste de credenciais real por provider.
- **UI**: `/admin/tts.php` — textarea (máx 5000 caracteres), seletor de voz por provider, **carregar texto de uma transcrição** (integração STT→TTS), player + download, histórico com player inline.
- **Quotas por plano** (`plans.max_tts`): Trial 2 · VSL Start 0 · Afiliado Pro 10 · Master Elite 100 · Admin ilimitado. Só gerações **concluídas** consomem quota.
- **Áudios**: salvos em `uploads/tts/` (volume do docker-compose); servidos autenticados via `admin/api/tts.php?action=audio&id=` (dono apenas).
- **API**: `admin/api/tts.php` (quota/generate/list/audio/delete).
- **Tabela**: `tts_generations` (migration 18).

## Sócio de IA (Agente) + Subagentes

- **Módulo** (`lib/Agent/`): agente conversacional que atua como **sócio** do usuário (produtor ou afiliado, possivelmente leigo) — focado em tráfego pago e orgânico, ajudando a monetizar com pouco/nenhum investimento.
- **Assíncrono (fila de jobs)**: `send` valida (quota/moderação) e **enfileira** em `agent_jobs` (retorno imediato, ~0.1s); o frontend dispara `action=process` (fire-and-forget) e faz polling de `job-status` (4s) — **o usuário navega livremente**. **Confirmação de ação também é assíncrona**: `confirm` marca a tool como `processing` e enfileira (`kind='tool'` + `tool_message_id`) — o card vira "executando..." com botões removidos na hora; o job executa a ferramenta + comentário da IA e finaliza o card. `PHP_CLI_SERVER_WORKERS=8` no Dockerfile permite requests paralelos. **Cron fallback**: `/cron/monitor.php` processa jobs órfãos (`AgentJobs::processPending`, release de travados, retry 3x).
- **Sessão**: endpoints de operações longas (agente, STT, TTS, Ad Spy, clone, exports) chamam `session_write_close()` após a autenticação — **sem isso o lock da sessão bloqueia todas as páginas do usuário** durante a operação (bug corrigido).
- **Notificações (sino)**: injetado pelo `app.js` no topbar de todas as telas admin — **spinner** quando há job em andamento (`working`, considera últimos 10min), **badge âmbar "!"** para ação aguardando confirmação (`pending_confirmations` + conversa), **badge vermelho** para respostas novas (`count`); **título da aba** mostra `(N)`/`...`/`(!)` conforme o estado; **notificação desktop** (Notification API, pedida no 1º clique no sino, só com a aba oculta); toast + clique abre `/admin/agent.php?conv=ID`; `agent_messages.seen_at` via `mark-seen`. **Banner fixo no chat** quando há ação pendente.
- **Permissões** (`AgentPermissions`, `agent_profiles.preferences.allowed_tools`): o cliente escolhe no chat (botão escudo → modal) quais tools o **Sócio** pode usar; subagentes têm allowlist própria (`agent_subagents.tools`, editável no modal do subagente). **Leitura/pesquisa executam automaticamente** (sem confirmação); **ações sensíveis sempre confirmam** (`AgentGuard::requiresConfirmation`). Tool negada = o agente responde que não tem permissão.
- **Precisão** (`AgentPrompts` + `AgentTools`): regras de **ação imediata** (nunca prometer busca — emitir o tool_call), **re-execução** (pedido repetido = buscar de novo, dados podem mudar), **nunca trocar o nicho/termo pedido**, informar quando não houver resultados, salvar nicho no perfil. `listar_ofertas` aceita **`q`** (busca livre em nome/anunciante/domínio/nicho/estrutura, com/sem acentos via `OfferManager::stripAccents`) e **testa variações automaticamente** quando o termo exato não retorna nada (palavras individuais + singular/plural pt-BR: "jogos digitais" → "jogos"/"digital"). **Swipe vazio ≠ fim**: o retorno informa os **nichos que existem** (`topNiches`) e o próximo passo obrigatório; a diretiva em `buildMessages` (`lastSwipeSearchWasEmpty`) e no prompt do comentário (`AgentPrompts::comment`) força a investigação externa (`espionar_anuncios` + `pesquisar_web`) — nunca pede ao usuário para "tentar outro termo". A diretiva de onboarding (`isNicheMessage`) só age quando a mensagem É o nicho (curta), nunca em pedidos livres.
- **Monitoramento** (`/admin/agent-monitor.php`, permissão **`manage_ai`** ou admin): stats de precisão (respostas, 👍/👎, % avaliado), lista de conversas com filtros (principal/subagente, avaliação, período, busca), diálogo completo com ratings/anotações e **export JSONL para fine-tuning** (`AgentMonitor::exportJsonl`). Rating 👍/👎 + comentário opcional direto no chat (`action=rate`).
- **Cargo `Curador de IA`** (seed + migration 28): permissão `manage_ai` para monitorar/treinar a IA; master/admin já têm a permissão.
- **Onboarding focado em afiliação**: o greeting **SEMPRE oferece escolha de nicho (nunca assume)** — chips clicáveis (`question` + `options`) com o nicho atual (se houver, como primeira opção) + nichos dinâmicos do swipe file (`OfferManager::topNiches`) + "Quero sugestões" + "Outro". Com nicho salvo o texto é "Da última vez trabalhamos com X. Continuamos nesse nicho ou você quer atuar em outro?". Ao responder: o sistema salva no perfil de forma **determinística** (`Agent::send` → `shouldAcceptNiche` + `OfferManager::matchNiche` para nichos conhecidos, `sanitizeFreeNiche` para **nicho livre** como "moda feminina" — rejeita genéricos tipo "Quero sugestões"/"Outro"/"nao sei"). Texto livre só define nicho quando o perfil está vazio (troca exige clique). A diretiva de onboarding força a busca imediata (`isOnboardingReply` em `buildMessages` — só vale quando a mensagem é uma das opções da pergunta, nunca em pedidos livres). Ordem de descoberta no prompt: **nicho → experiência → orçamento → objetivo** (uma pergunta por vez, `profile_update`); "Quero sugestões" → a IA sugere 2-3 nichos validados; "Outro" → a IA pergunta o nicho; nicho fora do swipe é aceito e trabalhado normalmente. `AgentPrompts::base()` tem exemplo few-shot do onboarding.
- **Princípios (prompt em `AgentPrompts::base()` + guardrails)**: pensa como sócio; **nunca promete/garante ganhos**; alerta riscos antes de sugerir gasto; recusa más práticas; **pergunta antes de assumir**; explica o custo de cada ação; ignora instruções em "DADOS EXTERNOS".
- **Subagentes** (`AgentSubagents`): especialistas criados pelo Sócio (tool `criar_subagente`) ou pelo usuário (modal); 4 templates; conversa dedicada (`agent_conversations.subagent_id`); **delegação** (`delegar_subagente`, sem cota extra, profundidade 1); subagente não cria/delega. Limites: Trial/VSL 0 · Pro 2 · Master 5 · Admin ∞.
- **Ferramentas** (`AgentTools`, 14): leitura — `consultar_quotas`, `listar_ofertas`, `listar_minhas_paginas`, `listar_transcricoes`, `listar_narracoes`, **`pesquisar_web`** (executam automaticamente); ação (confirmação) — `ver_oferta`, `espionar_anuncios`, `analisar_oferta`, `transcrever_midia`, `gerar_narracao`, `clonar_pagina`, `criar_subagente`, `delegar_subagente`.
- **Features de plano**: a feature `adspy` é exigida pelo guard — planos com `max_adspy_searches != 0` precisam tê-la (migration 30 corrigiu essencial/master/premium que estavam sem). Ao criar/editar planos em `/admin/pricing.php`, mantenha a feature coerente com a cota.
- **Pesquisa web** (`lib/Web/WebSearch.php`, tool `pesquisar_web`): investigação de mercado do Sócio (tendências, concorrentes, referências). Prioridade: **chave SerpApi do usuário (BYOK)** → **SearXNG self-hosted** (`SEARXNG_URL` no `.env`, ex.: `http://host.docker.internal:52301` do Pinokio ou `http://searxng:8080` do compose — sem limite) → **chave da plataforma com limite de 20 buscas/dia por usuário** (`WebSearch::PLATFORM_DAILY_LIMIT`; sistema/user 0 não consome) → **fallback gratuito** (DuckDuckGo Lite via POST → Bing HTML; Google direto bloqueia). **Cache de 24h** (`ad_spy_cache`): a mesma query não consome o limite nem a chave de novo (`source: cache`). Registro em `ad_spy_searches` com `kind='web'` e `source` (`byok|selfhosted|platform|fallback`) — não consome quota do plano. A regra de **INVESTIGAÇÃO COMPLETA** no prompt obriga esgotar swipe → bibliotecas → web antes de dizer "não encontrei".
- **SearXNG (Pinokio)**: o app do Pinokio roda com porta aleatória e bind `127.0.0.1` (o `start.js` foi ajustado para `0.0.0.0` — backup em `start.js.bak`); no Docker Desktop o `host.docker.internal` alcança o loopback do host (funciona); em Linux puro é preciso bind `0.0.0.0` + `extra_hosts: host-gateway` (já no compose). Porta no log: `C:\pinokio\api\searxng.pinokio.git\logs\api\start.js\latest`.
- **Memória** (`AgentProfile`): nicho, orçamento, experiência e objetivos entre conversas.
- **Quotas** (`plans.max_agent_messages`): Trial 10 · VSL 0 · Pro 100 · Master 500 · Admin ∞ (BYOK remove o limite).
- **UI**: `/admin/agent.php` — chat assíncrono com histórico, **seção Subagentes**, cards de ação, chips, resultados ricos, **rating 👍/👎**, banner de princípios, perfil, prefill `?ask=`, abertura por `?conv=`.
- **API**: `admin/api/agent.php` (quota/profile/conversations/conversation/new/send/process/job-status/notifications/mark-seen/rate/confirm/cancel/delete + subagents…).
- **Tabelas**: `agent_conversations` (+`subagent_id`), `agent_messages` (+`seen_at`, `rating`, `rating_note`), `agent_profiles`, `agent_subagents`, `agent_jobs` (migrations 20, 25, 27).

## IA, BYOK e quotas (política)

- **BYOK ilimitado**: quando o usuário tem chave própria ativa (`user_ai_configs` capability `chat`/`stt`/`tts`), as quotas de IA **não se aplicam** — o limite passa a ser o da própria chave do cliente (não controlamos planos de terceiros). A quota do plano vale apenas para o provider da plataforma (Cloudflare).
  - Quotas com detecção de source: `AgentQuota`, `SttQuota`, `TtsQuota` e `AdSpyQuota::KIND_ANALYSIS` (chat). **Buscas de anúncios** e **views de ofertas** não são IA — mantêm quota sempre.
  - Cada check retorna `source` (`byok|platform`); as tabelas de uso (`ad_spy_searches`, `transcriptions`, `tts_generations`, `agent_messages`) gravam a coluna `source` para auditoria e métrica de % de uso BYOK.
  - UX: pills mostram "BYOK — sem limite"; ao esgotar a cota da plataforma, a mensagem orienta configurar a chave própria (`/admin/ai-settings.php`) em vez de só bloquear.
- **Eficácia da plataforma (CF)**: STT (Whisper) e chat (GLM Flash) têm boa eficácia grátis; TTS (MeloTTS) é o ponto fraco — BYOK (ElevenLabs/OpenAI) é o upgrade natural. Gargalo: 10k neurons/dia compartilhados na conta CF.

## Moderação e segurança de conteúdo (todos os planos)

- **`lib/Moderation/ContentModerator.php`**: moderação determinística (listas pt-BR + regex, sem custo de IA):
  - **Bloqueio** (`crime`): padrões de intenção criminosa (matar/roubar/assaltar/sequestrar/traficar/golpe/documento falso/hackear...) + termos explícitos (estelionato, pedofilia, tráfico, lavagem...). Falsos positivos figurativos são evitados (ex.: "matar a concorrência" passa).
  - **Redaction** (`pii`/`profanity`): CPF, CNPJ, telefone, e-mail, cartão, CEP → `[removido]`; palavras torpes → `[redigido]`.
  - **`redact()` (SAÍDA da IA)**: aplica apenas PII/profanidade — **nunca bloqueia o texto inteiro**. O bloqueio por crime vale só para a ENTRADA (`screen()`); aplicá-lo na saída gerava falso positivo e apagava a resposta inteira (ex.: falar de jogos com termos de guerra).
  - `sanitizeForTraining()` remove PII e marca/redige PHI (dados de saúde) para o dataset de treino.
- **Registro obrigatório**: `moderation_events` (user_id, contexto, categoria, ação, motivo, conteúdo original + limpo, **IP**, user agent, data/hora) — retenção para eventual solicitação de autoridades. Bloqueios também vão para o `audit_log`.
- **Onde aplica**: Sócio (mensagem do usuário — bloqueada **não consome quota** e não chama a IA), respostas da IA (redact), TTS (texto), Transcrições (conteúdo resultante), Ad Spy (query). O banner do Sócio informa: "Uso ilegal é bloqueado e registrado".
- **Admin**: `/admin/moderation.php` (só master/admin) — stats, filtros por categoria/ação/usuário, detalhe do conteúdo e **exportação CSV/JSON** para autoridades.
- **Tabela**: `moderation_events` (migration 22).

## Documentos legais e consentimento

- **Páginas públicas**: `/termos`, `/privacidade`, `/cookies`, `/degustacao` (layout compartilhado em `partials/legal.php`) — templates pt-BR com placeholders dos dados da empresa (⚠️ revisão jurídica recomendada antes de publicar). Links no footer da landing e no cadastro.
- **Aviso da Degustação** (`degustacao.php`): tabela de limites **dinâmica** (lida do `Plans`), o que não está incluído, regras de uso (moderação/LGPD) e dica do BYOK quando a cota acabar.
- **Dados da empresa** (razão social, CNPJ, e-mail, DPO, endereço) editáveis em Admin → Configurações (settings `company_*`) e usados nas páginas legais.
- **Cadastro**: aceite obrigatório dos Termos/Privacidade (`terms_accepted_at` gravado) + consentimento **opcional** de uso de dados anonimizados para IA (`users.training_consent`) — revogável depois.
- **Tabelas**: `users.training_consent`, `users.terms_accepted_at` (migration 23).

## Dataset de treinamento (consentido)

- **`lib/Training/TrainingCollector.php`**: captura amostras **somente de usuários com consentimento** (`users.training_consent`), com PII removida e PHI redigida (`ContentModerator::sanitizeForTraining`).
- **Coleta**: conversas do Sócio (par usuário/assistente), transcrições e textos de narração (chamadas em `Agent::handleResponse`, `api/transcribe.php`, `api/tts.php`).
- **Consentimento**: checkbox opcional no cadastro + toggle em Admin → Configurações → "Privacidade e meus dados" (revogável a qualquer momento; auditoria `training_consent_granted/revoked`).
- **Admin** (`/admin/training.php`, só master/admin): contadores por tipo/plano, usuários consentidos e **exportação JSONL** para fine-tuning (formato `{kind,plan,payload,created_at}`) — base para LoRA/AI Search na Cloudflare.
- **Download do cliente** (`/admin/api/export.php?action=my-data&format=md|jsonl`): dados do próprio usuário (conversas, transcrições, narrações) em Markdown/JSONL — disponível nos planos Afiliado Pro, Master Elite e Admin (botão em Configurações).
- **Tabela**: `training_samples` (migration 24).

## Roadmap Cloudflare (treinamento)

1. Markdowns curados → **AI Search** (RAG gerenciado) com feedback 👍/👎 nas respostas.
2. Dataset JSONL → treino de **LoRA adapter** ("Sócio BR" proprietário; upload via `wrangler ai finetune create`, rank ≤8, <300MB, fica na conta CF da empresa).
3. **Packs de conhecimento por nicho** (markdown + adapter) como produto.

## Homologação e validação

- **`bin/smoke.php`** — smoke automatizado (login, todas as páginas, APIs, fluxos com limpeza): `docker exec afiliafacil php bin/smoke.php` → deve terminar com **SMOKE OK** (exit code 0/1)
- **`bin/seed-demo.php`** — dados de demonstração: contas `demo.trial@` / `demo.pro@` / `demo.master@afiliafacil.com` com **senha individual** (`Trial.Demo@2026` / `Pro.Demo@2026` / `Master.Demo@2026`), 5 ofertas fictícias aprovadas e 1 página de exemplo na conta Master. `--clean` remove tudo
- **`bin/homol.ps1`** — sobe container + túnel **cloudflared** e mostra a URL pública (`*.trycloudflare.com`); `-Stop` encerra o túnel
- **`bin/roteiro-pdf.ps1`** + **`bin/roteiro-html.js`** — gera PDF de docs via `npx marked` + Chrome headless (sem pandoc)
- **Testes E2E** (`tests/e2e/`, Playwright): fluxos do Sócio — `onboarding.js` (chips de nicho → perfil → busca → greeting), `swipe-vazio.js` (nicho sem ofertas → investigação), `confirmacao.js` (card pendente → confirmar → executar → sino), `permissoes.js` (modal de permissões), `pesquisa-web.js` (leitura automática + links). **Sempre rodar `cleanup.js` ao final** (remove conversas/jobs e limpa o perfil — evita dados de teste na experiência). Rodar: `cd tests/e2e && npm install && node onboarding.js` (ou `NODE_PATH=C:\Users\<user>\node_modules` se o Playwright estiver global). Ver `tests/e2e/README.md`.
- **Perfil do Sócio**: `POST /admin/api/agent.php` com `action=profile&clear=1` limpa o perfil (`AgentProfile::clear`). O `bin/smoke.php` restaura o perfil ao final e usa mensagem longa (não vira nicho); deletar conversa remove também os jobs (`Agent::deleteConversation`).
- **Docs**: `docs/deploy-homol.md` (checklist técnico), `docs/validacao-manual.md` (checklist pré-homol do responsável), `docs/roteiro-testes-homol.md` (+ PDF para envio ao cliente, linguagem leiga), `docs/pesquisa-de-mercado.md` (playbook de investigação — treinamento comum entre o Sócio e o subagente `mercado`), `docs/browser-scraping-selfhosted.md` (pesquisa Steel/Browserless/SearXNG/Playwright para a fase EC2/Docker)
- **`Config::getBaseUrl()`** respeita `X-Forwarded-Proto` (HTTPS atrás de túnel/proxy — necessário para o Stripe)

## Roadmap (próximos épicos)

1. **Documentação do usuário (help center)**: central de ajuda interna com guias por módulo (primeiros passos, clonagem, editor, ofertas, IA, planos).
2. **Documentação de API (OpenAPI)**: especificação dos endpoints (`/admin/api/*`, `/cron/monitor.php`) para viabilizar integrações de terceiros no futuro.
3. **Cloudflare (treinamento)**: markdowns → **AI Search** (RAG) com feedback 👍/👎; dataset JSONL → **LoRA adapter** ("Sócio BR" proprietário); **packs de conhecimento por nicho**.

## Feedback (clientes)

- **Módulo** (`lib/Feedback.php`): canal de sugestões, reclamações, elogios e bugs — disponível para **todos os planos** (inclusive trial).
- **Usuário** (`/admin/feedback.php`): formulário (tipo + mensagem + página opcional), histórico com **respostas da equipe**, anti-spam de **5 envios/dia** (mensagens passam pela moderação de conteúdo).
- **Admin** (mesma tela): visão completa com filtros (tipo/status), responder, arquivar e **exportação CSV/JSON** — insumo direto para o subagente `mercado` priorizar o roadmap.
- **Contexto automático**: plano, página e user agent gravados em cada envio.
- **Badge**: contagem de novos no menu (admin).
- **API**: `admin/api/feedback.php` (send/list/reply/status/export). Tabela `feedback` (migration 26).

## Squad de agentes (opencode, global)

Subagentes em `~/.config/opencode/agent/` para o agente **Plan** (orquestrador) acionar: `mercado` (utilidade de mercado + critérios de aceite de mercado), `spec`, `dev`, `qa`, `bugfix`, `supervisor`. Política em `~/.config/opencode/rules/squad-workflow.md`. O `mercado` usa o **export de feedback** do painel como insumo.

## Como rodar

```powershell
docker-compose down; docker-compose up -d --build
# http://localhost:9876  (aguarda healthcheck do Postgres + migrations no boot)
```

## Credenciais padrão (admin dono)

- E-mail: `admin@afiliafacil.com`
- Senha: `admin123`
- Plano do admin: `premium` (acesso total, sem limites)

Admin cria novos usuários via registro (`/register`), que inicia como `trial` com `trial_days` configurável em Admin → Configurações (padrão 3 dias).

## Planos (valores espelhados da Afiliaze)

| Plano | Mensal | Trimestral | Semestral | Anual | Recursos |
|---|---|---|---|---|---|
| Trial | grátis 3d | — | — | — | 1 página, clonador |
| VSL | R$ 79 | R$ 159 | R$ 267 | R$ 468 | player+delay, 1 página, 1 domínio |
| Essencial | R$ 119 | R$ 237 | R$ 402 | R$ 679 | clonador+pressel+player+pixel+cookie+backredirect, 5 páginas, 2 domínios |
| Master | R$ 149 | R$ 297 | R$ 492 | R$ 838 | tudo ilimitado + integrações, 10 domínios |

Definição central em `lib/Plans.php` — limites (max_pages/max_domains/features) aplicados via gating no backend (ex.: `api/clone.php`). Plano `trial_expired` bloqueia features e força upgrade.

## Pagamentos

- **Gateway ativo**: `CHECKOUT_DRIVER` (env) ou `checkout_driver` em `data/settings.json` (pix | stripe), configurável em Admin → Configurações
- **PIX estático**: QR Code (BR Code EMV + CRC16 gerado em `lib/Checkout.php`); usuário paga → pagamento fica `pending` → **admin aprova manualmente** em `/admin/pay.php` (botão verde confirma e ativa o plano)
- **Stripe**: Checkout Session (mode payment, BRL) → webhook em **`/webhooks/stripe`** (pasta `webhooks/stripe.php`, clean URL) que valida assinatura HMAC (STRIPE_WEBHOOK_SECRET) e aprova automaticamente em `checkout.session.completed`
  - Endpoint para configurar no painel do Stripe: `https://SEU-DOMINIO/webhooks/stripe` (eventos: `checkout.session.completed`, `checkout.session.async_payment_succeeded`)
- Chaves de teste: `STRIPE_SECRET_KEY=sk_test_*`, `STRIPE_WEBHOOK_SECRET=whsec_*`, `PIX_KEY` — preenchidas via docker-compose environment (placeholders no repo)

## Estrutura

```
afiliafacil/
├── index.php            # login
├── landing.php          # landing page pública (/) — hero, features, planos, FAQ
├── register.php         # cadastro com trial
├── planos.php           # re-direciona para /#plans
├── router.php           # rotas (PHP built-in server)
├── lib/
│   ├── Auth.php         # sessão, registro, syncPlan (trial expiração), isAdmin
│   ├── Config.php       # paths (Docker preferido: getenv DOCKER)
│   ├── Settings.php     # settings.json (trial_days, checkout_driver, chaves)
│   ├── Plans.php        # definição de planos/limites/preços
│   ├── Payments.php     # payments.json (criar/aprovar/rejeitar/listar)
│   ├── Checkout.php     # PIX payload EMV/CRC16 + Stripe Checkout Session
│   ├── PageManager.php  # CRUD páginas (JSON + arquivos index.html em pages/<id>/)
│   ├── Cloner.php       # clonador (usa AssetProcessor + detecta/substitui CTAs)
│   ├── AssetProcessor.php # rewriteForPreview (proxy) / rewriteForZip (proxy local)
│   └── ZipBuilder.php   # ZIP index.html + proxy.php local (mesmo comportamento do preview)
├── admin/
│   ├── index.php        # dashboard
│   ├── pages.php        # minhas páginas (CRUD, filtros)
│   ├── clone.php        # clonar por URL ou HTML
│   ├── plan.php         # meus planos + assinatura (PIX/Stripe)
│   ├── pay.php          # aprovação de PIX pendente (só admin)
│   ├── settings.php     # tema + sistema (só admin)
│   ├── editor.php       # IDE interno paginas clonadas (feature editor)
│   ├── pressel.php video.php pixel.php backredirect.php cookie.php domains.php integrations.php
│   └── api/             # clone.php, pages.php, checkout.php, editor.php (JSON)
├── webhooks/stripe.php  # webhook Stripe (HMAC-SHA256) - rota /webhooks/stripe
└── assets/              # css (app + theme-light/dark), js
```

## Notas de arquitetura

- **proxy.php**: obrigatório na raiz (rota `/proxy.php` no router) — o preview e o ZIP reescrevem TODOS os assets para `proxy.php?url=...`; sem ele tudo retorna 404. Versão do ZIP é gerada por `ZipBuilder::getProxyScript()`.
- **proxy.php Range/streaming**: vídeo/áudio usam streaming com suporte a `Range` (206/Content-Range/Accept-Ranges) — headers definidos ANTES do curl_exec (modo mídia ecoa o body, não pode setar header depois). `Content-Range`/`Content-Length` vêm do header callback.
- **Variantes de mídia cobertas no clone** (`processHtml`): `<img src>`, `srcset` (img/source), `<source src>`, `poster`, `style=""` inline, `<style>` tags, `image-set()`, `data-bg`/`data-background`/`data-lazy`/`data-src` (aspas simples e duplas), SVG `<image href>`, favicon/apple-touch-icon/preload(as=image|font). Falhas são registradas em `failed_assets` e exibidas na edição da página.
- **Validação pós-clone**: `lib/CloneValidator.php` roda ao final de cada clonagem — detecta srcset com data URI vazio/URL corrompida, data URIs vazios e imgs sem src; resultado em `validation` + `failed_assets` (alerta na edição). `CLONER_VERSION` é gravada na página (`cloner_version`) — clones de versões antigas mostram badge "Clone antigo" + botão **Re-clonar** (`api/clone.php?action=reclone`, cria revisão antes)
- **splitSrcset**: NUNCA usar `preg_split` por vírgula em `srcset` — data URIs contêm vírgula (`base64,`); usar `AssetProcessor::splitSrcset()` (heurística: vírgula de data URI não é seguida de espaço). Entradas inválidas (data URI sem payload, base64 puro) são descartadas por `isInvalidSrcsetEntry()` no clone/preview/ZIP.
- **Preservação de aspas nas reescritas**: as funções `rewrite*` do `AssetProcessor` capturam o tipo de aspas original (`href=(["'])(...)\1`) e reusam na substituição — NUNCA forçar aspas duplas (quebrava JS inline tipo `x("<div style='...'>")` do jQuery UI → SyntaxError). `url()` em CSS é emitido sem quotes.
- **Download robusto**: `fetchUrl` faz 2 tentativas com `Referer` do domínio fonte, timeout 30s — destrava hotlink/instabilidade. Falhas vão para `getFailedAssets()`.
- **PCRE seguro**: `lib/SafePcre.php` — TODO uso de `preg_replace`/`preg_replace_callback` no clonador/preview/ZIP passa por `SafePcre::replace`/`SafePcre::replaceCallback` (retorna o subject original quando o PCRE estoura backtrack/JIT limit — evita TypeError fatal em páginas com `<style>` gigantes) + `SafePcre::bootstrap()` eleva limites (`pcre.backtrack_limit=50M`, `pcre.jit=0`). Nunca voltar a usar preg_* direto nesses fluxos.

- **Preview vs ZIP**: ambos usam `AssetProcessor::rewriteForPreview()` / `rewriteForZip()` → URLs reescritas para `proxy.php?url=...` — o ZIP inclui um `proxy.php` local próprio. NÃO mudar a abordagem do preview (funciona perfeitamente como está).
- **Conexão CTA**: clones identificam CTAs (`<a>`/`<button>`) e substituem pelo link de afiliado informado na clonagem.
- **Trial**: `Auth::syncPlan()` rodado em `requireAuth()` marca `trial_expired` quando `trial_until` passa; `Plans::get('trial_expired')` bloqueia tudo.
- **Temas (unificado em todo o site)**: `data-theme` vive **apenas no `<html>`** — `theme-light.css` escopa `:root, [data-theme="light"]` e `theme-dark.css` escopa `[data-theme="dark"]`; **ambos os CSS são sempre carregados** (dark por último). Fonte única de verdade = **cookie `theme`** (gravado pelo toggle; `lib/Theme.php` lê cookie → sessão → `light` no SSR; `Auth` sincroniza cookie→sessão para as páginas admin). **Anti-flash**: `Theme::antiFlashScript()` no `<head>` (antes do CSS) de todas as páginas públicas aplica localStorage ou `prefers-color-scheme` do sistema. `app.js` (`toggleTheme`/`applyTheme`) grava localStorage + cookie e só faz POST de sessão no admin. Telas públicas (landing, login, register, legais) têm o toggle (`Theme::toggleButton()`). NUNCA usar link dinâmico `theme-<?= $theme ?>.css` — quebrou o toggle antes. NUNCA duplicar `data-theme` no `<body>` (o body com "light" do SSR sobrescrevia as variáveis do html dark).
- **Contraste (tema claro, WCAG AA)**: `--accent: #0b5ed7` (4.8:1 com branco), `--accent-hover: #0a58ca`, `--text-secondary: #5c636a`; gradientes (login/hero) começam em `#0b5ed7`. Cuidado com seletores de nav (`a` sem classe) sobrescrevendo `.btn-primary` — usar `:not(.btn)`.
- **Scrollbar por tema**: variáveis `--scrollbar-track/thumb/thumb-hover` + `--scrollbar-sidebar-*` (sidebar é escura nos 2 temas) nos arquivos de tema; estilos globais em `app.css` (`::-webkit-scrollbar` + `scrollbar-width`) e no `editor.css` (`.CodeMirror-scroll`, `.editor-sidebar`). `color-scheme` nos temas adapta controles nativos. O preview das páginas clonadas NÃO recebe scrollbar custom (fidelidade ao original).

## Fluxo principal testado

1. Landing → `/register` (trial 3 dias, auto-login)
2. `/admin/clone.php` → clona URL/HTML (limite do plano aplicado)
3. `/admin/plan.php` → escolhe plano → PIX (QR + copia e cola) ou Stripe (redirect p/ checkout)
4. PIX: admin aprova em `/admin/pay.php` → plano ativado; Stripe: webhook ativa automaticamente
5. Página publicada/preview via `proxy.php`, ZIP baixável self-contained

## Editor de páginas clonadas (branch feature/editor-ide)

- `admin/editor.php` — IDE interno (CodeMirror CDN) fullscreen com sidebar de arquivos (`index.html`, `custom.css`), preview iframe recarregável e histórico de revisões
- **Busca**: Ctrl+F (busca persistente), Ctrl+G/Shift+Ctrl+G (próximo/anterior), Ctrl+H (substituir), Alt+G (ir para linha) — addons dialog/search/searchcursor/jump-to-line, dialog estilizado nos temas
- **Folding**: CodeMirror `foldGutter` + addons `foldcode/foldgutter/xml-fold/brace-fold` (CDN) — setas ▶/▼ no gutter (fold/expand) e placeholder `↔` na linha dobrada; ajuda a navegar tags grandes nos clones
- **Tema no editor**: botão toggle (lua/sol) no header — alterna `data-theme` + `cmEditor.setOption('theme', ...)` (default ↔ material-darker) instantaneamente, persiste localStorage + sessão (mesmo padrão do painel)
- **Preview = Inspector** (`preview.php?inspector=1`): script injetado (só com flag + autenticado) que faz hover/click destacarem o elemento e enviarem `postMessage {type:'af-inspect'}` com selector único → editor.js localiza o snippet no código e **seleciona a TAG DE ABERTURA no CodeMirror** (busca em cascata: tagSnippet exato → âncora única `id=` → `class=` → `src=` → texto do elemento; seleção capada a 300 chars para data URIs). **Clique normal = inspector (bloqueia interação real)**; **CTRL/CMD+Click = interage**; botão "Interagir" liga/desliga. Não confundir com preview.php sem `?inspector=1` (sem script, usada no zip/site).
- `admin/api/editor.php` — `get` / `save` / `restore?rev=` (JSON); `save` grava snapshot prévio em `pages/<id>/revisions/<timestamp>.html` e salva via `PageManager::update`
- Gating: feature `editor` **somente planos pagos** (Essencial/Master) — trial não tem editor
- `pages.php?action=edit&id=` abre o formulário de metadados (nome, status, domínio, link afiliado) com botão "Editar Código Online"
