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

- **Isolamento de dados (app)**: `Auth::canAccessPage()`/`requirePageAccess()` — páginas só são acessíveis pelo dono ou admin (aplicado em pages/preview/download/editor/APIs); listagem e stats filtradas por usuário (`PageManager::listByUser`)
- **Roles Postgres (least privilege)**: runtime conecta com `afiliafacil_app` (sem DDL — só DML + sequences); migrações usam o owner via `DB_MIGRATION_USER`/`DB_MIGRATION_PASSWORD`. A role é criada/atualizada no boot por `bin/migrate.php` (`DB_APP_USER`/`DB_APP_PASSWORD`)
- **Produção**: use `DATABASE_URL` com `sslmode=require` e senhas fortes via secrets (nunca os defaults de dev)
- RLS não implementado (decisão de escopo — isolamento é feito na app + role restrita)

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
