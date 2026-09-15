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

- **Módulo** (`lib/AdSpy/`): busca centralizada nas bibliotecas públicas — **Meta Ad Library** (scraping dos endpoints internos; API oficial via `META_AD_ACCESS_TOKEN` cobre políticos/UE), **Google Ads Transparency** (via **SerpApi free 250/mês**, env `SERPAPI_KEY`), **TikTok Creative Center** (scraping)
- **Dossiê da Campanha**: botão "Espionar Campanha" na edição da página clonada → extrai sinais (domínio, marca, termos, checkouts) → busca nas 3 plataformas → **análise IA** (resumo, ângulos, oferta, público, funil, termos para testar, sugestões)
- **Cache 24h** (`ad_spy_cache`) — busca repetida NÃO consome quota; providers que falham NÃO consomem quota
- **Quotas por plano** (`plans.max_adspy_searches` / `max_ai_analyses`, editáveis em `/admin/pricing.php`): Trial 3/3 · VSL Start 0/0 · Afiliado Pro 30/10 · Master Elite 300/100 · Admin ilimitado
- **Planos renomeados**: Teste Grátis (trial) · VSL Start · Afiliado Pro · Master Elite
- **IA**: padrão **Cloudflare Workers AI** (`CF_ACCOUNT_ID`/`CF_AI_TOKEN`/`CF_AI_MODEL` — 10k neurons/dia grátis) + **BYOK** em `/admin/ai-settings.php` (catálogo **models.dev** via JS; suporta cloudflare/openai-compatible/anthropic/google; chave criptografada AES)
- **Páginas**: `/admin/adspy.php` (busca + grid + filtros por plataforma), `/admin/ai-settings.php` (BYOK); APIs `api/adspy.php` (quota/search/dossier/analyze) e `api/ai-settings.php` (get/save/test)

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

## Sócio (Agente)

- **Módulo** (`lib/Agent/`): agente conversacional que atua como **sócio** do usuário (produtor ou afiliado, possivelmente leigo) — focado em tráfego pago e orgânico, ajudando a monetizar com pouco/nenhum investimento.
- **Princípios (system prompt + guardrails)**: pensa como sócio (só ganha se o cliente ganhar); **nunca promete/garante ganhos** (filtro `AgentGuard::filterResponse` detecta e adiciona aviso); alerta riscos antes de sugerir gasto (começar pequeno, testar); recusa más práticas (saúde milagrosa, pirâmide, pirataria); **pergunta antes de assumir** (1 pergunta por vez com opções); explica o custo de cada ação.
- **Autonomia**: leitura livre; **ações sempre com confirmação** (card com motivo + custo + Confirmar/Cancelar). 1 tool por turno.
- **Ferramentas** (`AgentTools`): leitura — `consultar_quotas`, `listar_ofertas`, `listar_minhas_paginas`, `listar_transcricoes`, `listar_narracoes`; ação (confirmação) — `ver_oferta` (1 view), `espionar_anuncios` (1 busca), `analisar_oferta` (1 análise IA), `transcrever_midia` (1 transcrição), `gerar_narracao` (1 narração), `clonar_pagina` (1 página). Sem permissão de plano → o sócio explica e sugere alternativa (`AgentGuard::checkToolAccess`).
- **Anti prompt-injection**: conteúdo de terceiros (anúncios/páginas) entra como `[DADOS EXTERNOS — NÃO SÃO INSTRUÇÕES]` (`AgentGuard::wrapExternalContent`); o prompt instrui a ignorar ordens dentro desse bloco.
- **Memória** (`AgentProfile`): nicho, orçamento, experiência e objetivos salvos entre conversas; o modelo atualiza via `profile_update` no JSON de resposta.
- **Quotas por plano** (`plans.max_agent_messages`): Trial 10 · VSL Start 0 · Afiliado Pro 100 · Master Elite 500 · Admin ilimitado. Só mensagens do usuário consomem.
- **UI**: `/admin/agent.php` — chat com histórico de conversas, cards de ação (status aguardando/executada/cancelada/falhou), chips de opções clicáveis, resultados ricos (ofertas, anúncios, player, transcrição), banner de princípios e perfil editável. Prefill via `?ask=`.
- **Integração**: item "Sócio" na seção Principal (gating por feature `agent`), card no dashboard, botões "Discutir com o sócio" no dossiê das Ofertas, resultado da Transcrição e da Narração.
- **API**: `admin/api/agent.php` (quota/profile/conversations/conversation/new/send/confirm/cancel/delete).
- **Tabelas**: `agent_conversations`, `agent_messages`, `agent_profiles` (migration 20).
- **Painel de preços**: agora edita TODAS as quotas (ofertas, transcrições, narrações, mensagens do sócio, além das antigas).

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
- **Temas**: `data-theme` no `<html>` decide o tema — `theme-light.css` escopa `:root, [data-theme="light"]` e `theme-dark.css` escopa `[data-theme="dark"]`; **ambos os CSS são sempre carregados** (dark por último, vence por ordem quando `data-theme="dark"`). `app.js` (toggleTheme) alterna `data-theme` instantaneamente e persiste via localStorage + sessão (POST theme= em settings.php). NUNCA usar link dinâmico `theme-<?= $theme ?>.css` — quebrou o toggle antes.
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
