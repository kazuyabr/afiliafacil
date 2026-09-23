# n8n self-hosted — setup com AfiliaFacil (10 min)

## O que é o n8n

Ferramenta **gratuita, self-hosted e open-source** para automação — como Zapier/Make,
mas você hospeda no seu servidor e **paga $0 por execução**. No AfiliaFacil, os templates
prontos (em `workflows/templates/`) transformam suas campanhas: monitoramento, alertas,
captação de leads e relatórios — sem programar.

## 1. Subir o n8n (Docker, 3 linhas)

```bash
docker run -d --name n8n -p 5678:5678 \
  -v ~/.n8n:/home/node/.n8n \
  n8nio/n8n:latest
```

Abra `http://localhost:5678` → crie a conta admin (só na primeira vez).

## 2. Importar um template AfiliaFacil

```text
Workflows (menu lateral) → Import from File →
selecione: workflows/templates/monitor-landing-pages.json
```

## 3. Configurar as variáveis de ambiente

Os templates usam variáveis como `{{$env.META_AD_ACCOUNT_ID}}`. Configure em:

```text
Settings → Environment variables
```

Variáveis que você pode definir (exemplos):

| Variável | Onde pega | Uso |
|---|---|---|
| `META_AD_ACCOUNT_ID` | `developers.facebook.com` → aplicativo | Métricas de campanha |
| `FACEBOOK_PAGE_ID` | Facebook → Página → Sobre | Publicação |
| `INSTAGRAM_ACCOUNT_ID` | Instagram Business → usuario o. | Publicação |
| `N8N_HOST` | `http://localhost:5678` | URLs internas |

Para chaves de API específicas (SerpApi, ManyChat): veja o template, tem campos marcados
com `[configure sua chave]`. Recomendado: troops **Configurar no .env** (abaixo).

## 4. Persistir chaves com seguranca (recomendado)

Em vez de editar o JSON da workflow, defina no `.env` do n8n:

```bash
# ~/.n8n/.env
META_ACCESS_TOKEN=EAAxxxxxxxx
SERPAPI_KEY=xxx
MANYCHAT_API_KEY=xxx
```

## 5. Ativar o workflow

No topo da tela: toggle **Active** → ON. Pronto — roda sozinho.

## Templates recomendados (por prioridade)

1. 🥇 `monitor-landing-pages.json` — sua pagina nunca fica fora do ar sem aviso
2. 🥈 `monitor-campanhas.json` — gasto alto = email no momento
3. 🥉 `relatorios-semanais.json` — relatorio fechado toda sexta
4. `espionar-anuncios.json` — inteligencia do nicho (novo concorrente detectado)

## Perguntas rapidas

**Preciso de programacao?** Zero. Sustituir tokens chaves.

**Funciona com AfiliaFacil sem n8n?** Sim — os templates **são extras**. O AfiliaFacil funciona 100% sozinho.

**Quanto custa hospedar o n8n?** $0 self-hosted (máquina do dono), ou ~€20/mes no n8n Cloud.

**Posso personalizar?** Sim — os templates sao editaveis (nodes visuais, logica JS disponível).
