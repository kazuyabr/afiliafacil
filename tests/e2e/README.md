# Testes E2E (Playwright)

Testes de ponta a ponta dos fluxos do **Sócio de IA** — cobrem onboarding, swipe vazio,
confirmação de ações, permissões e pesquisa web. Servem para validação manual e homologação.

## Pré-requisitos

- App rodando: `docker compose up -d --build` (http://localhost:9876)
- Node.js + Playwright (a partir desta pasta):
  ```bash
  npm install          # instala o playwright (node_modules é gitignored)
  npx playwright install chromium
  ```
- Credenciais: `admin@afiliafacil.com` / `admin123`

## Como rodar

```bash
cd tests/e2e
node onboarding.js      # onboarding: chips de nicho -> perfil -> busca imediata -> greeting personalizado
node swipe-vazio.js     # nicho sem ofertas (Games): não deixa o cliente perdido, propõe investigação
node confirmacao.js     # ação sensível: card pendente -> confirmar -> executar -> sino/notificação
node permissoes.js      # modal de permissões do Sócio (liga/desliga tools)
node pesquisa-web.js    # pesquisar_web executa sozinha (leitura) e renderiza os links
```

Ou via npm scripts: `npm run test:onboarding`, `npm run test:swipe-vazio`, etc.

> Se o Playwright estiver instalado globalmente (`C:\Users\<user>\node_modules`), rode com
> `NODE_PATH=C:\Users\<user>\node_modules` para reaproveitar sem instalar de novo.

## Limpeza entre execuções

Os testes criam conversas/jobs. Para limpar (e zerar o perfil do admin):

```bash
docker exec afiliafacil php -r "require_once '/app/lib/Config.php'; require_once '/app/lib/Database.php'; Database::init(); \$p = AfiliaFacil\Models\AgentProfile::where('user_id', 1)->first(); if (\$p) { \$p->niche = ''; \$p->save(); } AfiliaFacil\Models\AgentJob::query()->delete(); AfiliaFacil\Models\AgentMessage::query()->delete(); AfiliaFacil\Models\AgentConversation::query()->delete(); echo 'limpo';"
```

## Observações

- Use `waitUntil: 'domcontentloaded'` — imagens externas podem travar o evento `load`.
- A IA responde em ~3-8s (modelo padrão Nemotron 3 120B); os testes aguardam o job via polling.
- Falhas de IA transitórias não indicam bug do fluxo — rode novamente antes de reportar.
