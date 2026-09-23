# Workflows (n8n self-hosted) — Templates prontos p/ importar

Cada arquivo `.json` em `templates/` é um workflow completo que você **importa no seu n8n**
(livre, self-hosted) e já funciona com o AfiliaFacil — sem programar.

## Como importar no n8n

1. Abra seu n8n → **Workflows → Import from File** (ou cole no editor via `Ctrl+V` depois de copiar o `.json`).
2. Nos nós marcados com `[configure sua chave]`, substitua pelos seus tokens reais
   (Meta, SerpApi, ManyChat — a plataforma mostra onde pegar em **IA > Busca de Anúncios**).
3. Habilite o workflow (toggle ON) e clique em **Save**.

## Templates disponíveis

| Arquivo | O que faz | Frequência | Ideal para |
|---|---|---|---|
| `monitor-campanhas.json` | Lê métricas da **Meta Ads** a cada 4h e alerta quando o gasto passa do limite | 4 em 4h | Saber imediatamente quando a campanha gasta demais |
| `monitor-landing-pages.json` | Testa se sua página está **no ar** (+ tracking) a cada 30min | 30 em 30min | Nunca perder venda por pagina caida |
| `monitor-ofertas.json` | Compara payout atual vs. anterior nas ofertas e **alerta na subida** | 6 em 6h | Entrar enquanto a oferta está subindo |
| `relatorios-semanais.json` | Consolida gasto, vendas e ROI dos últimos 7 dias e manda por email | 1x/semana | Fechamento semanal/projecao |
| `captcha-leads-manychat.json` | Webhook para **captar lead** no AfiliaFacil e criar contato no **ManyChat** | instantaneo | Quem preenche formulario vira contato WhatsApp imediato |
| `followup-whatsapp.json` | Após clique sem compra: **espera 30min** → dispara fluxo; espera 2d → ultimo toque | por evento | Abandono de checkout |
| `espionar-anuncios.json` | A cada 2 dias, conta anunciantes no SerpApi/Google Ads e alerta se mudou | 2 em 2d | Inteligencia do nicho |
| `publicacao-multi-destino.json` | Um conteudo publicado → Facebook Page + Instagram ao mesmo tempo | por evento | Repost de Criativo em 2 cliques |

## Ordem sugerida de uso

1. `monitor-landing-pages.json` — proteção da estrutura (mais critico).
2. `monitor-campanhas.json` — controle de gasto/descontrole.
3. `relatorios-semanais.json` — fechamento da semana.
4. `captcha-leads-manychat.json` — se você vende por WhatsApp/Messenger.

## Onde pegar as chaves

- **SerpApi** (Google Ads): `serpapi.com` — gratis: 250/mes. Depois configure em **AfiliaFacil > IA > Busca de Anúncios** para o tool `Espionar Anuncios` usar a mesma chave.
- **Meta/Facebook**: `developers.facebook.com` → aplicativo → `ads_read` → token temporário.
- **ManyChat**: `manychat.com` → Settings → API Key (`docs/manychat-integracao.md`).

## Limitações importantes

- O n8n self-hosted é **gratuito** (Apache 2.0). O AfiliaFacil **não hospeda** — para agendar vocês podem hospedar na sua VPS/EC2 ou usar o n8n Cloud (trial).
- Templates são **exemplos funcionais** — personalize gatilhos, URLs e limites ao seu negócio.
- Nenhum template envia dados ao AfiliaFacil — tudo fica na sua máquina.
