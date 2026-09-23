# Checklist do Dono — Fase 4 (pós-MVP)

Tudo que depende **de você** (não dá para delegar): credenciais, conteúdo do negócio e julgamento humano.
Marque cada item conforme avança — isso vira o plano de entrada no mercado.

---

## 1. Credenciais de produção (chaves/tokens)

| Item | Estado | O que fazer | Onde guarda |
|---|---|---|---|
| Conta Cloudflare de produção | [ ] | Criar conta CF (ou upgrade) + token API + Account ID | `.env` prod: `CF_ACCOUNT_ID`, `CF_AI_TOKEN` |
| SerpApi | [ ] | Criar/renovar (plano grátis = 250 buscas/mês) | `.env` prod: `SERPAPI_KEY` |
| Meta Access Token | [ ] | developers.facebook.com → app + `ads_read` → token | `.env` prod: `META_AD_ACCESS_TOKEN` |
| Stripe produção | [ ] | sk_live + webhook real (não só teste) | `.env` prod: `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` |
| PIX real | [ ] | Chave PIX do banco/instituição | `.env` prod: `PIX_KEY`, `PIX_NAME`, `PIX_CITY` |
| Domínio de produção | [ ] | Comprar domínio + SSL/redirect | DNS + hosting |
| Email transacional | [ ] | SMTP (Gmail App Password, Mailgun, Resend...) | `.env` prod: `SMTP_*` |

> Após editar o `.env`: `docker compose up -d --force-recreate`
> Depois: `docker exec afiliafacil php bin/smoke.php` → **SMOKE OK**

---

## 2. Conteúdo do negócio (só você decide)

| Item | Estado | O que fazer |
|---|---|---|
| Preços dos planos | [ ] | Confirmar R$ 79/VSL · R$ 119/Pro · R$ 149/Master (ou ajustar) — edite em `/admin/pricing.php` |
| Texto da landing | [ ] | Revisar `landing.php`: hero, features, FAQ, chamada do Sócio |
| Políticas legais | [ ] | `/termos` e `/privacidade` com SEUS dados (razão social, CNPJ, email, endereço) — edite em Config → Company |
| Ofertas demo | [ ] | 3 ofertas reais de sucesso p/ a demo (escolher o que impressionar) |
| Senha master | [ ] | Trocar de `admin123` para senha forte em produção |
| Backup do banco | [ ] | `docker-compose.yml` com `volumes: db_data` (atual) ou backup externo |

---

## 3. Homologação (só você roda)

| Item | Estado | O que fazer |
|---|---|---|
| Subir túnel | [ ] | `.\bin\homol.ps1` → pegar URL `https://xxxx.trycloudflare.com` |
| Rodar checklist | [ ] | Abrir `docs/validacao-manual.md` e marcar cada bloco (60-80min 1a vez) |
| Entregar ao cliente | [ ] | Enviar `docs/roteiro-testes-homol.pdf` + URL do túnel + senhas demo |
| Coletar feedback | [ ] | Cliente preenche o checklist final do PDF e devolve |
| Cron de ofertas | [ ] | Ativar `CRON_KEY` + agendar (ver `deploy-homol.md` §4) ou rodar mini jobs |

---

## 4. Julgamento do demo (após o cliente testar)

| Item | Estado | O que fazer |
|---|---|---|
| Triagem de bugs | [ ] | O que o cliente achou estranho → corrige, melhor ou pós-MVP |
| 2ª rodada | [ ] | Reagendar teste com correções |
| Definir price de venda | [ ] | Decidir o preço real com base no cliente teste |
| Sinal verde de vendas | [ ] | Tudo passado → lançar pagamento real |

---

## 5. Sinal verde de lançamento (mínimo)

Antes de qualquer cliente pagar de verdade, confirme:

- [ ] `bin/smoke.php` em produção: **SMOKE OK**
- [ ] Login admin + demo funcionam com senhas fortes (não padrão)
- [ ] PIX/CAPI/etc. com chaves reais (não teste)
- [ ] Landing com seu email/empresa
- [ ] `/p/{slug}` estável sob a URL final (não localhost)
- [ ] Ao menos 3 ofertas demo validadas na área Criativos
- [ ] Slogan do afiliado: "Pague quando quiser" é claro
- [ ] nenhum item oculto vazando p/ trial no menu

---

## 6. Após o 1º pagamento (retorno ao roadmap)

Quando o primeiro PIX cair na conta:

- [ ] Cancelar tarefa pendente do cliente? (não zera quota) — já funciona
- [ ] Monitorar `Audit` por uso suspeito
- [ ] Coletar feedback real (agora com peso maior)
- [ ] Decidir: entra com Fase 4 cheia (Cloudflare B, help center, ganhos) ou itera o MVP?

---

## Referências

| Arquivo | Uso |
|---|---|
| `docs/deploy-homol.md` | Setup + credenciais + checklist técnico |
| `docs/validacao-manual.md` | Checklist dos blocks MVP |
| `docs/roteiro-testes-homol.pdf` | Cliente (gerar) |
| `docs/avaliacao-utilidade.md` | O que é produto/por quê |
| `docs/videos.md` | Branches + slot Drift |
| `docs/n8n-setup.md` + `docs/manychat-integracao.md` | Parceiros |
| `docs/checklist-dono.md` | Este arquivo |

---

> 💡 **Dica**: imprima este checklist ou abra no celular — é o seu "ponto de controle" antes de qualquer conversa com investidor ou cliente pagante.
