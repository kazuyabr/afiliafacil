# Validação Manual — AfiliaFacil (confirmar antes da homologação)

Checklist para **você** (dono) validar tudo antes de enviar ao cliente.
Acesse `http://localhost:9876` (ou a URL do túnel) e marque cada item.

> Rode antes `docker exec afiliafacil php bin/smoke.php` → **SMOKE OK** = base de pé.

---

## 1. Acesso e ambiente
- [ ] Login admin (`admin@afiliafacil.com` / `admin123`)
- [ ] Login contas demo (`demo.trial`→Trial, `demo.pro`→Pro, `demo.master`→Master)
- [ ] Tema claro/escuro alterna e persiste após recarregar
- [ ] 2FA: ativar, sair, entrar (etapa 2), validar, desativar
- [ ] Cadastro novo em `/register`: aceite dos termos obrigatório

## 2. Menu (por jornada) — abra tudo no menu (sem link quebrado)
- [ ] **Meu Negócio**: Dashboard com cards **Meu Negócio hoje** (pendências + próximos passos + Top ofertas) + "Meu Plano"
- [ ] **Criar**: Clonador, Pressel, Vídeos (ffmpeg admin)
- [ ] **Inspirar**: Espionar Anúncios, Ofertas Escalando
- [ ] **IA**: Sócio de IA
- [ ] **Minha Oferta**: Player das Páginas, Pixel, Back Redirect, Cookie, Integrações
- [ ] **Admin** (só admin): Monitor da IA, Usuários, Preços, Cobrança, Auditoria, Moderação, Treinamento, Configurações
- [ ] **Conta**: Feedback, Configurações (com Avançado: IA/BYOK, Transcrições, Narração, Storage)

## 3. Páginas e clonador
- [ ] Clonar URL real, preview renderiza (assets via proxy), ZIP baixável
- [ ] **Editor de código**: editar + salvar + revisão criada
- [ ] **Editor visual** (novo): clicar em h1/img/link → form à esquerda → aplicar sem código → salvar
- [ ] **Aviso inteligente**: trocar link por outro do MESMO domínio original → aviso sugere link de afiliado
- [ ] Limite de páginas por plano (Trial = 1) bloqueia
- [ ] Re-clonar cri uma revisão, não sobrescreve direto

## 4. Planos e cobrança
- [ ] "Meu Plano" mostra plano atual correto em cada conta
- [ ] Cobrança (admin) lista PIX pendentes; aprovar/rejeitar funciona
- [ ] Sem menção a "domínios" nos labels dos planos (migration 33)
- [ ] Checkout PIX: QR + copia e cola gerados

## 5. Espionagem (Ad Spy)
- [ ] Busca por **URL** (ex.: `https://hotmart.com/pagina`) → extrai domínio e busca certo
- [ ] Busca por dominio puro (ex.: `hotmart.com`) funciona
- [ ] Pills de status: mostram `SerpApi (sua chave)`, `Biblioteca pública`, `Scraping direto` por provider
- [ ] Cards mostram **"há X dias"** e botão Clonar quando tem landing_page
- [ ] Dossiê a partir de página clonada signals+ads reais
- [ ] Sem gasto em quota: páginas pendentes não consomem `max_adspy_searches`

## 6. Ofertas Escalando
- [ ] Apenas ofertas **aprovadas** (não demos `demo-*`) aparecem no público
- [ ] Dossiê abre, views decrementam 1 por oferta, reabrir a mesma não gasta
- [ ] Curadoria (admin): aprovar/rejeitar em lote funciona
- [ ] **Botões Baixar/Variação** por criativo (variação muda o hash — GD)
- [ ] "Aviso Entity ID" aparece no topo da aba Criativos
- [ ] A hierarquia da oferta conta com `@`? (curadoria admin)

## 7. Sócio de IA + Conhecimento (novo)
- [ ] Sócio **pergunta antes de assumir** (nicho/orçamento)
- [ ] Ele **executa** (listar_ofertas) e comenta o resultado
- [ ] Rating 👍👎 funciona e aparece no **Monitor**
- [ ] **Base de conhecimento** (🔑 livro): subir .md → salvar → Sócio responde com esse conteúdo em mensagens seguintes
- [ ] Subagente também usa a base (docs do subagente + do Sócio)
- [ ] Resposta vazia da IA mostra fallback amigável (nunca texto em branco)

## 8. Monitor da IA + Auditoria + Moderação (admin)
- [ ] Monitor: cards clicáveis (Conversas/Respostas/👍/👎/Sem avaliação) abrem modal
- [ ] Auditoria: filtros por ação, usuário, período, busca + só falhas + export CSV
- [ ] Moderação: modal original × limpo; e-mail do usuário em vez de ID
- [ ] Moderação aparece na url quando há conteúdo bloqueado

## 9. Rastreamento e página pública (novo)
- [ ] Editar página → bloco **Rastreamento (Pixel + CAPI)** com Pixel ID, Token CAPI, Código de teste, Google, TikTok
- [ ] Página pública: acessar `/p/{slug}` sem login → renderiza e conta 1 view
- [ ] Com pixel configurado: código fbq injetado com **mesmo** eventID do server-side (dedupe)
- [ ] Rascunho em `/p/{slug}` → 404
- [ ] `/p/track` sem CAPI configurado → JSON de erro gracioso (não 500)
- [ ] Bot não incrementa view (facebookexternalhit)

## 10. Vídeos (ffmpeg — admin)
- [ ] Menu **Criar → Vídeos** visível só para plano com feature `video`/`videos` ou admin
- [ ] Upload de MP4 real, importar por URL, cortar trecho, capa, queimar legenda .srt, variação (muda hash)
- [ ] Cada operação gera novo arquivo com sufixo; original intacto
- [ ] Excluir via UI remove o arquivo

## 11. Workflows (pos-MVP — revisão final)
- [ ] Workflow templates em `workflows/templates/` (8 arquivos JSON válidos)
- [ ] `docs/n8n-setup.md` + `docs/manychat-integracao.md` com passos claros

## 12. Configurações — Avançado斴
- [ ] **Configurações → Avançado**: IA/BYOK, Transcrições, Narração, Armazenamento, Cargos acessiveis
- [ ] IA (BYOK): salvar chave → pill "BYOK — sem limite"; testar sem salvar valida a digitada
- [ ] Modelos locais (LM Studio): detectar host.docker.internal, carregar/descarregar VRAM

## 13. Legal e dados
- [ ] `/termos`, `/privacidade`, `/cookies`, `/degustacao` com tabela dinâmica
- [ ] Configurações → Privacidade: consentimento + download de dados (Markdown/JSONL)
- [ ] Conteúdo criminoso no Sócio **bloqueado sem consumir quota** + registrado na Moderação

---

## Resumo rápido (para o PDF do roteiro)

**Total previsto**: 60–80 minutos na primeira vez, 25min em re-teste.

**Os 5 críticos** (se falhar, não entrega):
1. Login + smoke OK
2. Clonar página + preview + ZIP
3. Página pública (`/p/{slug}`) serve sem login + CAPI injetado
4. Sócio de IA responde de verdade (chave CF válida)
5. Sem item oculto vazando no menu p/ conta trial (Domínios/Transcrições/Narração/BYOK/Cargos/Armazenamento/Treinamento)

**Se algo falhar**: anote módulo + passo + o que aconteceu + print → vira plano de correção.

---

## Onde estão os documentos finais

| Arquivo | Conteúdo |
|---|---|
| `docs/roteiro-testes-homol.md` | Roteiro do cliente (linguagem leiga) |
| `docs/roteiro-testes-homol.pdf` | Versão PDF para enviar (email/impressão) |
| `docs/validacao-manual.md` | Este checklist (dono) |
| `docs/deploy-homol.md` | Checklist técnico (setup, túnel, cron) |
| `AGENTS.md` | Regras do projeto + ponteiro p/ todos os docs |
| `docs/avaliacao-utilidade.md` | Por que este MVP (vereditos, corte, lacunas) |
