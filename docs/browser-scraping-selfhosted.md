# Browser e scraping self-hosted (custo zero) — pesquisa para o roadmap EC2/Docker

Pesquisa realizada em 2026-09 para responder: **como dar ao Sócio de IA acesso a fontes públicas
(Meta Ad Library, Google Ads Transparency, web) sem depender de APIs pagas**, pensando na fase
EC2/Docker (escala).

## Situação atual (VPS única, custo zero)

| Necessidade | Solução atual | Limitação |
|---|---|---|
| Meta Ad Library | Scraping dos endpoints internos (funciona sem browser) | Frágil a mudanças do Meta; sem JS rendering |
| Google Ads Transparency | SerpApi (chave do usuário ou plataforma) | Pago; busca por domínio |
| TikTok Creative Center | Scraping direto | Frágil |
| Pesquisa web (Sócio) | **SearXNG self-hosted (implementado)** → SerpApi → **DDG Lite (POST)** → Bing HTML | DDG/Bing podem bloquear em volume |

### SearXNG implementado (2026-09)

- **Provider no `WebSearch`**: prioridade `BYOK SerpApi → SearXNG (SEARXNG_URL) → plataforma (20/dia) → DDG → Bing`, com cache de 24h.
- **Dev (Pinokio)**: app `searxng.pinokio.git` com porta aleatória (log em `C:\pinokio\api\searxng.pinokio.git\logs\api\start.js\latest`), `start.js` ajustado para bind `0.0.0.0` (backup `start.js.bak`); `SEARXNG_URL=http://host.docker.internal:52301` no `.env`; `extra_hosts: host-gateway` no `docker-compose.yml`.
- **Produção (EC2/Docker)**: subir o container oficial no compose (`searxng/searxng`, porta fixa 8080, `formats: [html, json]`) e apontar `SEARXNG_URL=http://searxng:8080` — sem depender do Pinokio.
- Testado: 20 resultados em ~1,4s; JSON habilitado no `settings.yml` do Pinokio (`formats: html, json, csv, rss`).

## Opções avaliadas (todas self-hosted, $0 de licença)

### 1. SearXNG — meta-buscador com API JSON ⭐ (melhor custo/benefício)

- **Licença**: AGPL-3.0. **Docker**: `docker run -p 8888:8080 searxng/searxng:latest` (~200 MB RAM).
- Agrega Google, Bing, DDG, Brave e outros **sem chave de API**; API em `/search?q=...&format=json`.
- **Atenção**: o formato JSON precisa ser habilitado em `settings.yml` (`search.formats: [html, json]`);
  instâncias públicas geralmente desabilitam (403).
- **Uso no AfiliaFacil**: subir o SearXNG no `docker-compose.yml` da VPS/EC2 e apontar o
  `WebSearch` para a instância própria (novo provider `searxng` com prioridade sobre o fallback
  DDG/Bing) — busca própria, sem limite diário e sem bloqueio de datacenter.
- Comando: `docker compose up -d` (template oficial em `github.com/searxng/searxng/container/`).

### 2. Steel Browser — API de browser para agentes ⭐ (licença permissiva)

- **Licença**: Apache-2.0 (permissiva, uso comercial ok). **Docker**:
  `docker run -p 3000:3000 -p 9223:9223 ghcr.io/steel-dev/steel-browser`.
- Sessões persistentes (cookies/localStorage), stealth básico, proxies, extensões; conecta via
  Playwright/Puppeteer/Selenium (CDP); endpoints prontos de `/scrape`, `/screenshot`, `/pdf`.
- Requisitos: Docker 20.10+, 4 GB RAM, 10 GB disco. Beta — fixar versão antes de produção.
- **Uso no AfiliaFacil**: scraping com JS rendering (Meta Ad Library, Google Ads Transparency sem
  SerpApi), screenshots de landing pages das ofertas, extração de criativos.

### 3. Browserless — workhorse dockerizado

- **Licença**: SSPL-1.0 **ou** comercial (SSPL não é OSI; uso comercial/hospedado exige licença paga).
- REST (`/content`, `/pdf`, `/screenshot`, `/scrape`) + Playwright/Puppeteer via WebSocket;
  `shm_size: "2g"` obrigatório.
- **Uso**: alternativa ao Steel, mas a licença pede revisão jurídica antes de embarcar no produto.

### 4. Playwright headless puro

- **Licença**: Apache-2.0. Roda direto no container (sem serviço extra), mas **sem stealth** e sem
  gestão de sessões — é camada de script, não de infraestrutura.
- **Uso**: tarefas pontuais de scraping/renderização em cron (ex.: validar landing pages).

### 5. Camoufox / Clawbrowser — anti-detecção (só se necessário)

- Camoufox (MIT): Firefox modificado no C++ (anti-fingerprint de engine). Clawbrowser: Chromium com
  perfis isolados + proxies (grátis para uso comercial). Só valem a pena se os alvos bloquearem
  Chromium padrão — hoje não é o caso.

## Recomendação faseada

| Fase | Ação | Custo |
|---|---|---|
| **Agora** | Manter fallback DDG Lite + Bing (já implementado) + BYOK SerpApi | $0 |
| **Próxima** | Subir **SearXNG** no compose da VPS/EC2 → provider `searxng` no `WebSearch` | ~200 MB RAM |
| **Escala** | Subir **Steel Browser** no EC2 → scraping JS (Meta/Google) + screenshots de ofertas | ~2–4 GB RAM |
| **Se bloquear** | Avaliar Camoufox/Clawbrowser (anti-detect) | $0 + manutenção |

## Decisões de arquitetura

- **Nunca** depender de instância pública de SearXNG (rate limit/bloqueio) — só instância própria.
- Proxies residenciais só se houver bloqueio real de datacenter (custo mensal; decisão futura).
- Manter o `WebSearch` com **cadeia de fallback** (SearXNG → SerpApi → DDG Lite → Bing): qualquer
  fonte que falhar não derruba a pesquisa.
- Scraping deve respeitar `robots.txt`/termos das plataformas — a responsabilidade de uso é do
  operador da instância.
