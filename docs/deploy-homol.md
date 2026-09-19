# Deploy de Homologação — AfiliaFacil

Guia rápido para subir o ambiente de homologação (túnel local) e entregar ao cliente.

## Pré-requisitos

- Docker Desktop rodando
- `cloudflared` instalado: `winget install --id Cloudflare.cloudflared`
- `.env` preenchido (ver seção abaixo)

## 1. Variáveis essenciais no `.env`

| Variável | Para quê | Obrigatória? |
|---|---|---|
| `POSTGRES_PASSWORD` / `DB_PASSWORD` / `DB_MIGRATION_PASSWORD` | banco | sim |
| `APP_KEY` | criptografia (R2, chaves BYOK) | sim |
| `CF_ACCOUNT_ID` + `CF_AI_TOKEN` | IA da plataforma (Sócio de IA, análises, STT, TTS) | sim (para módulos de IA) |
| `CF_AI_MODEL` / `CF_WHISPER_MODEL` / `CF_TTS_MODEL` | modelos padrão | opcional |
| `SERPAPI_KEY` | Google Ads no Ad Spy (free 250/mês) | opcional |
| `META_AD_ACCESS_TOKEN` | Meta Ad Library oficial | opcional |
| `CRON_KEY` | monitor de ofertas via cron | opcional (gerar no painel) |
| `PIX_KEY` / `PIX_NAME` / `PIX_CITY` | checkout PIX | sim (para testar pagamento) |
| `STRIPE_SECRET_KEY` / `STRIPE_WEBHOOK_SECRET` | checkout Stripe | opcional (teste) |
| `CHECKOUT_DRIVER` | `pix` ou `stripe` | sim |

Após alterar o `.env`, recrie o container:

```powershell
docker compose up -d --force-recreate
```

## 2. Subir a homologação

```powershell
# 1) dados de demonstração (contas + ofertas + página de exemplo)
docker exec afiliafacil php bin/seed-demo.php

# 2) smoke test (deve terminar com "SMOKE OK")
docker exec afiliafacil php bin/smoke.php

# 3) túnel + URL pública
.\bin\homol.ps1
```

O script mostra a URL `https://xxxx.trycloudflare.com` para enviar ao cliente.

## 3. Checklist antes de enviar ao cliente

- [ ] `bin/smoke.php` retornou **SMOKE OK** (0 falhas)
- [ ] `bin/seed-demo.php` criou contas e ofertas demo
- [ ] Login nas 4 contas (admin + 3 demo) funcionando — **cada conta demo tem senha própria**:
      `demo.trial` → `Trial.Demo@2026` · `demo.pro` → `Pro.Demo@2026` · `demo.master` → `Master.Demo@2026` · admin → `admin123`
- [ ] Sócio de IA responde de verdade (chave CF configurada) — não apenas a mensagem de fallback
- [ ] Transcrição de um áudio/vídeo curto funcionando
- [ ] Narração de um texto curto funcionando
- [ ] Ad Spy com pelo menos 1 plataforma respondendo (Meta/Google/TikTok)
- [ ] Checkout PIX exibindo QR + copia e cola (não precisa pagar)
- [ ] Páginas legais acessíveis (`/termos`, `/privacidade`, `/cookies`, `/degustacao`)
- [ ] Roteiro `docs/roteiro-testes-homol.md` revisado e PDF gerado

## 4. Cron de ofertas na homologação

O túnel **não executa cron automaticamente**. Para manter o swipe file atualizado:

- Use os botões **"Buscar ofertas agora"** e **"Atualizar métricas agora"** em Admin → Ofertas → Curadoria, ou
- Agende no Windows (Agendador de Tarefas):

```powershell
# exemplo: a cada 6h, chama o endpoint local
docker exec afiliafacil sh -c "curl -s 'http://localhost:9876/cron/monitor.php?key=SUA_CRON_KEY'"
```

Gere a `CRON_KEY` em Admin → Ofertas → Curadoria → "Gerar nova chave".

## 5. Derrubar / limpar

```powershell
.\bin\homol.ps1 -Stop                          # encerra o túnel
docker exec afiliafacil php bin/seed-demo.php --clean   # remove dados demo
docker compose down                            # derruba os containers (mantém o banco)
docker compose down -v                         # derruba E apaga o banco (cuidado)
```

## 6. Problemas comuns

| Sintoma | Causa provável | Solução |
|---|---|---|
| URL do túnel não aparece | cloudflared não instalado / sem internet | instalar via winget e rodar de novo |
| Cliente não acessa a URL | PC desligado / Docker parado / túnel encerrado | manter ligado e rodar `homol.ps1` |
| Sócio de IA responde "IA não configurada" | `CF_AI_TOKEN`/`CF_ACCOUNT_ID` vazios | preencher `.env` e recriar o container |
| Checkout Stripe com URL errada | proxy não repassou o protocolo | o app já lê `X-Forwarded-Proto`; recriar o container |
| Quotas bloqueando os testes | contas demo com plano certo? | Trial 10 msgs, Pro 100, Master 500 |
| Upload grande falhando | limite do php.ini | já configurado em 24MB no Dockerfile |
