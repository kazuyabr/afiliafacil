# AfiliaFacil

Plataforma completa para afiliados: clonador de páginas, pressel, player de vídeo, pixel, back redirect, cookie de afiliado, domínios, integrações e assinaturas com PIX/Stripe. Concorrente direto da Afiliaze (afiliaze.com.br).

## Stack

- **PHP 8.2** (sem framework, sem composer)
- **Docker** — built-in PHP server, porta **9876** (única do projeto; verificar antes se está livre: `netstat -an | Select-String ":9876"`)
- Dados em JSON (`data/`), páginas geradas em `pages/`, sem banco de dados

## Como rodar

```powershell
docker-compose down; docker-compose up -d --build
# http://localhost:9876
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
- **Preview = Inspector** (`preview.php?inspector=1`): script injetado (só com flag + autenticado) que faz hover/click destacarem o elemento e enviarem `postMessage {type:'af-inspect'}` com selector único → editor.js localiza o snippet no código e **seleciona o trecho no CodeMirror** + scroll + painel lateral do elemento. **Clique normal = inspector (bloqueia interação real)**; **CTRL/CMD+Click = interage** (links/vídeos); botão "Interagir" liga/desliga o modo interativo. Não confundir com preview.php sem `?inspector=1` (sem script, usada no zip/site).
- `admin/api/editor.php` — `get` / `save` / `restore?rev=` (JSON); `save` grava snapshot prévio em `pages/<id>/revisions/<timestamp>.html` e salva via `PageManager::update`
- Gating: feature `editor` **somente planos pagos** (Essencial/Master) — trial não tem editor
- `pages.php?action=edit&id=` abre o formulário de metadados (nome, status, domínio, link afiliado) com botão "Editar Código Online"
