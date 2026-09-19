# Pesquisa de Mercado — Playbook (treinamento comum entre agentes)

Documento de referência compartilhado entre o **Sócio de IA** (agente do produto, prompt em
`lib/Agent/AgentPrompts.php`) e o **subagente `mercado`** do squad opencode. Toda investigação de
mercado — do Sócio ou do squad — segue este fluxo.

## 1. Ordem obrigatória das fontes

| # | Fonte | Ferramenta | Quando usar |
|---|-------|-----------|-------------|
| 1 | **Swipe file interno** (ofertas aprovadas, validadas, com métricas de escala) | `listar_ofertas` (com `q`) / `ver_oferta` | Sempre primeiro — é o dado mais confiável e barato |
| 2 | **Bibliotecas de anúncios públicas** (Meta Ad Library, Google Ads Transparency, TikTok Creative Center) | `espionar_anuncios` | Quando o swipe não tem o termo/nicho ou é preciso ver anúncios ativos agora |
| 3 | **Web aberta** (contexto, tendências, concorrentes, notícias, referências) | `pesquisar_web` | Quando é preciso contexto que anúncio não dá (tamanho de mercado, sazonalidade, players) |

**Regra de ouro**: é proibido responder "não encontrei" sem ter passado pelas 3 camadas. Se as 3
falharem, diga **o que foi consultado** e proponha o próximo passo (termo alternativo, outro
nicho, aviso ao usuário).

## 2. Checklist de investigação de um nicho

1. **Termo** — respeite o termo do usuário (não troque por conta própria; só sugira alternativas
   após esgotar o pedido original).
2. **Swipe interno** — `listar_ofertas` com `q` (busca em nome, anunciante, domínio, nicho,
   estrutura; acentos são normalizados). Registre: quantas ofertas, scores, escala (ads + %),
   estruturas predominantes (VSL, quiz, low ticket, infoproduto, carta).
3. **Anúncios ativos** — `espionar_anuncios` com o termo (providers `meta`, `google`, `tiktok`).
   Registre: volume de anúncios, anunciantes recorrentes, criativos (formatos), tempo no ar
   (anúncio antigo = oferta validada), landing pages.
4. **Contexto de mercado** — `pesquisar_web` com 1–2 consultas focadas (ex.: "nicho X tendências
   2026", "nicho X concorrentes"). Registre: players, canais, sazonalidade, regulações.
5. **Síntese** — responda com números (não adjetivos), separando **fato verificado** (veio de
   ferramenta) de **interpretação** (sua análise). Inclua riscos antes de qualquer sugestão de
   gasto.

## 3. Critérios de qualidade (o que é uma boa oportunidade)

- **Validação**: anúncio ativo há semanas/meses (tempo no ar > volume de anúncios).
- **Escala**: presença em múltiplos provedores e/ou muitos criativos ativos.
- **Estrutura**: página de vendas clara (VSL, quiz, carta) com CTA e checkout identificáveis.
- **Aderência**: casa com o perfil do usuário (nicho, orçamento, experiência) — `AgentProfile`.
- **Risco**: regulação (saúde, finanças), saturação (muitos anunciantes iguais), dependência de
  tráfego pago caro.

## 4. Como reportar (formato)

```
[FATO] N anúncios ativos de M anunciantes (Meta/Google/TikTok, consultado agora)
[FATO] K ofertas no swipe com score médio S e escala +X%
[FATO] Pesquisa web: players A, B, C; tendência T (fonte: URL)
[ANÁLISE] Oportunidade/risco: ...
[PRÓXIMO PASSO] 1 ação concreta (com custo de cota explícito)
```

- Nunca prometer/garantir ganhos; tráfego é teste e probabilidade.
- Explicar o custo de cada ação (quota) antes de executar.
- Citando web: incluir o domínio/URL da fonte.

## 5. Limites e custos

- `listar_ofertas` / `pesquisar_web`: leitura — não consomem quota do plano.
- `espionar_anuncios`: consome 1 busca (ou é liberado com BYOK de busca).
- `ver_oferta`: consome 1 visualização de oferta (dedupe mensal).
- `analisar_oferta`: consome 1 análise IA (ou liberado com BYOK de chat).
- `pesquisar_web`: usa a chave SerpApi do usuário (BYOK, ilimitado) → senão a chave da plataforma
  (limite de **20 buscas/dia por usuário**) → senão fallback gratuito (DuckDuckGo Lite/Bing).
- Moderação de conteúdo se aplica a todas as consultas (bloqueio de uso ilegal é registrado).

## 6. Uso no squad opencode (subagente `mercado`)

- O subagente `mercado` usa o **export de feedback** do painel (`/admin/feedback.php` → export
  CSV/JSON) + este playbook como insumo para priorizar o roadmap.
- Toda feature nova passa por ele para definir **critérios de aceite de mercado** (o que o cliente
  espera ver funcionando), em parceria com o `qa`.
- Divergência entre o playbook e o comportamento do agente = bug de prompt (corrigir em
  `AgentPrompts` e registrar no monitor `/admin/agent-monitor.php`).
