# Avaliação de Utilidade e Corte do MVP — AfiliaFacil

Documento vivo do alinhamento produto × mercado. Base: pesquisa do subagente `mercado`
(concorrentes, preços, stack real — ver §10 Fontes) + decisões do responsável pelo produto.
Última revisão: 2026-09-22 (bloco P4).

## 1. Premissas

- **ICP**: afiliado brasileiro de infoproduto que roda tráfego pago (Meta/Google/TikTok)
  e precisa de estrutura própria (página, cookie, pixel, VSL).
- **Concorrente direto**: Afiliaze (clonador, pressel, hospedagem, player, espião).
  Hotmart/Monetizze/Kiwify/Braip **não** são concorrentes — são a camada de
  marketplace/checkout (gratuitos p/ o afiliado). O AfiliaFacil vive na camada que
  eles não cobrem: estrutura própria.
- **Receita (MVP)**: só assinatura (PIX/Stripe). Sem participação nos lucros de afiliados.
- **Não somos hospedagem**: sem DNS/DDNS/SSL por domínio. Publicação via slug da plataforma.

## 2. Stack real do afiliado BR (o que ele paga hoje)

1. **Estrutura própria** — clonar + hospedar + cookie + pixel (dor central; ~10 concorrentes pagos).
2. **Player de VSL** — VTurb R$ 97–597/mês; Pluma R$ 397/ano.
3. **Rastreamento server-side/CAPI** — UTMify, RedTrack (US$ 149/mês). Pixel puro perde 20–40%.
4. **Espionagem** — Meta Ad Library (grátis) + BigSpy (US$ 9–99/mês) / AdSpy (US$ 149/mês).
5. **TTS/Narração** — NarreAki, Astrix (perfil Shopee/UGC); p/ VSL, dentro do fluxo de criativos.
6. **Acessórios** — encurtador, QR, link WhatsApp.
7. **Suporte em horas** via WhatsApp (reclamações do setor são sobre suporte lento).

## 3. Veredito por item do menu

| Item | Veredito | Por quê |
|---|---|---|
| Dashboard | MANTER | Mesa; deve mostrar tempo até primeiro valor |
| Sócio de IA | MANTER (com trava) | Só vale se EXECUTAR ações, não chat genérico |
| Minhas Páginas | MANTER | O "cofre" do afiliado |
| Clonador | MANTER (prioridade) | A maior dor do mercado |
| Player de Vídeo | MANTER | Afiliado de VSL já paga isso à parte |
| Rastreamento | MANTER (evoluir p/ CAPI) | Pixel sem CAPI = checklist |
| Back Redirect | MANTER | Padrão de conversão, custo baixo |
| Cookie | MANTER (core) | A razão da estrutura própria |
| Espionar Anúncios | MANTER (fundir c/ Ofertas) | Valor = curadoria BR, não espelho da Ad Library grátis |
| Ofertas Escalando | MANTER | Retenção semanal se curado e atualizado |
| Monitor da IA | MANTER (admin) | Operação interna, sem investimento em UI |
| IA (BYOK) | OCULTAR → Configurações | Fricção alta; concorrentes embutem a IA |
| Transcrições | OCULTAR (pós-MVP) | Só vale amarrada ao fluxo (VSL → presell/copy) |
| Narração | OCULTAR (volta integrada) | Volta dentro do fluxo oferta → criativo narrado |
| Domínios | REMOVER | Não somos hospedagem; era stub sem backend |
| Integrações | MANTER (avaliar; Webhook 1º) | Ver §6 |
| Usuários / Preços / Cobrança | MANTER | Billing e operação |
| Auditoria / Moderação | MANTER (admin) | Segurança, LGPD, compliance de clonagem |
| Treinamento (dataset) | OCULTAR (admin) | Zero valor percebido; conhecimento vai p/ dentro do Sócio |
| Cargos | OCULTAR (pós-MVP) | Só vale p/ agências |
| Armazenamento (R2) | OCULTAR → Meu Plano/Config | Jargão de infra; afiliado compra "páginas no ar" |
| Feedback | MANTER | Insumo direto do roadmap |
| Meu Plano / Configurações | MANTER | Básico de SaaS |

## 4. Menu final (MVP)

```
MEU NEGÓCIO     Dashboard · Minhas Páginas · Meu Plano
CRIAR           Clonador · Pressel · Vídeos (ffmpeg)
INSPIRAR        Espionar Anúncios + Ofertas Escalando (curadoria BR)
IA              Sócio de IA (c/ conhecimento .md) · Subagentes
MINHA OFERTA    Player · Pixel (c/ CAPI) · Back Redirect · Cookie · Integrações
ADMIN           Monitor · Usuários · Preços · Cobrança · Auditoria · Moderação · Feedback · Configurações
CONTA           Feedback · Configurações (Avançado: BYOK, STT, TTS, Storage, Cargos)
```

## 5. Lacunas (mercado × produto)

| # | Lacuna | Destino |
|---|---|---|
| L1 | Editor visual in-place (clicar e editar, sem HTML) | **MVP (P10)** — base existe (inspector localiza o elemento) |
| L2 | CAPI / server-side tracking | **MVP (P11)** — Afiliaze já tem |
| L3 | Captura de leads + popup + checkout pré-populado | Pós-MVP |
| L4 | Verificação de domínio (Meta/Hotmart/Kiwify) | Pós-MVP |
| L5 | Programa de afiliados do próprio SaaS | Pós-MVP (growth) |
| L6 | Delay sincronizado + anti-bot | Delay parcial existe; resto pós-MVP |

Corrigido na pesquisa: Pressel, Editor (código), Delay básico e Quizz **já existem** — não são lacunas.

## 6. Integrações — avaliação (P4.2)

A tela era stub (6 botões "Conectar" mortos) — convertida em roteiro honesto ("Em breve").
Ordem de construção pós-MVP:

1. **Webhook genérico** (1º) — POST de eventos (clonagem, transcrição, coleta) → conecta n8n/Zapier/Make e libera milhares de apps com 1 integração.
2. **Checkouts** (Kiwify/Hotmart/Eduzz/Monetizze) — base do dashboard de ganhos + resgate.
3. **Publicação WordPress/FTP** — amplia TAM (parte do mercado tem WP).
4. **Chat/email** (ManyChat/Mailchimp/ActiveCampaign) — após chat nativo + workflows.
5. **Planilhas** (Google Sheets) — relatórios.

## 7. Modelo de negócio (delimitação)

- **Nossa receita**: assinatura (PIX com aprovação manual + Stripe automático).
- **Ganhos do afiliado**: pagos por Kiwify/Hotmart/etc., fora da plataforma (MVP).
- **Pós-MVP**: dashboard centralizado de ganhos + solicitação de resgate via API de cada
  plataforma — sem processar o pagamento, sem participação nos lucros.
- **Publicação**: `afiliafacil.com/p/{slug}` (SSL + hospedagem inclusos, sem DNS).

## 8. Critérios de aceite de mercado (p/ o `qa`)

- **CA-M1**: conta nova → "colar URL → clonar → trocar link → publicar" em ≤ 3 cliques.
- **CA-M2**: elemento da página editável in-place, sem ver HTML.
- **CA-M3**: página publicada marca cookie e dispara pixel/CAPI sem código.
- **CA-M4**: VSL com autoplay + CTA no pause + delay sincronizado, sem VTurb à parte.
- **CA-M5**: "Ofertas Escalando" decide o dia em 30s (oferta, criativo, tempo, link).
- **CA-M6**: suporte humano em horas, por canal de mensagem.
- **CA-M7**: vendas atribuídas por campanha via server-side, não só cliques.
- **CA-M8**: fatura previsível (sem surpresa de "plays"/storage).

## 9. Roadmap

- **Fase 1 — MVP vendável**: P0.5 → P3.5 ✅ (concluída)
- **Fase 2 — Completude + provas**: P4 → P11 + workflows (em curso)
- **Fase 3 — Homologação e demo**: validação → homologação → demo investidores
- **Fase 4 — Pós-MVP**: Cloudflare B (AI Search/LoRA/packs) · help center · doc API ·
  Drift streaming · packs por nicho · leads/popup · verificação de domínio ·
  programa de afiliados · TTS integrado · ganhos/resgate · integrações (§6)

## 10. Fontes (pesquisa `mercado`, 2026-09)

afiliaze.com.br (+ ajuda.afiliaze.com.br) · replic.app.br (R$ 39–59/mês) ·
clicopy.app (R$ 97/ano) · clonou.site (R$ 30/mês) · affiliates.tools (R$ 79,90/mês) ·
iacopi.com.br · elevpages.com · arktrix.com · presselldrop.com · filtrify.com.br ·
vturb.com (R$ 97–597/mês) · plumavideo.com (R$ 397/ano) · utmify · redtrack.io (US$ 149/mês) ·
adspy.com (US$ 149/mês) · bigspy (US$ 9–99/mês) · narreaki.com · astrixia.com.br ·
hotmart.com (afiliados/blog) · proteste.org.br (reclamações) · encurtaturbo.com.
[NÃO VERIFICADO] preço atual do Afiliaze; volume/churn do setor; conversões prometidas por blogs.
