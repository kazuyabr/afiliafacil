# Ciclo de vida de entrega — AfiliaFacil

> **Objetivo**: cada entrega seja potencialmente colocada em produção **sem conferência
> humana**, porque todos os gates são **executáveis e verificados com evidência**.
> Processo inspirado nas práticas de grandes empresas (Definition of Done, tronco único,
> testes como gate, entrega atômica, rollback simples), adaptado à realidade deste repo.

## Regras duras (vale para agentes e humanos)

1. **Spec primeiro** — tarefa não-trivial sem spec completa (gate S) não começa.
   Spec incompleta = dev bloqueado, não "assumido".
2. **Visual atômico com backend** — nenhuma UI entregue sem o endpoint/contrato
   funcionando **na mesma entrega**. Tela sem backend = entrega incompleta; backend sem
   tela acessível = entrega incompleta (salvo entrega puramente de API, justificada na spec).
3. **Responsividade por padrão** — toda tela nova/alterada passa nos 3 breakpoints do
   gate G4 (360 / 768 / 1280). "Funciona no meu monitor" não é aceite.
4. **Testes verdes** — nenhum commit com suíte vermelha. Bug exige teste de regressão
   que **falha antes e passa depois** da correção.
5. **Evidência > opinião** — qualquer afirmação sobre o estado do sistema precisa de
   saída de comando/teste anexada. "Deve funcionar" não é aceite.
6. **Sem invenção** — URLs, APIs, permissões, comportamentos de terceiros e campos de
   dashboard só existem depois de **verificados na fonte** (doc oficial, página real,
   comando real). Dúvida → verificar ou perguntar; nunca inventar.
7. **Gate verde = condição de produção** — `bin\quality-gate.ps1` em modo full é
   pré-condição de commit de entrega. Sem gate verde, não há entrega.

## Os 5 gates do ciclo

```
Pedido ──► [S] Spec ──► [I] Implementação ──► [T] Testes ──► [Q] Gate executável ──► [A] Auditoria ──► commit/push
```

### Gate S — Spec (antes de código)

Checklist obrigatório (spec reprovada se faltar qualquer item):

- [ ] **Escopo**: o que entra e o que explicitamente NÃO entra.
- [ ] **Critérios de aceite testáveis** (1 por requisito, verificáveis por comando/teste
      ou clique reproduzível). "Fica bonito" não é critério.
- [ ] **Contrato**: endpoints/params/respostas novos OU campos/telas afetados (UI ∩ backend).
- [ ] **Responsividade**: telas afetadas + como serão verificadas (G4).
- [ ] **Testes previstos**: quais suítes/arquivos de teste serão criados ou alterados.
- [ ] **Riscos/rollback**: o que pode quebrar e como reverter (1 commit = 1 rollback).
- [ ] **Fontes**: qualquer fato de terceiro (Meta, X, TikTok...) com link/verificação citada.

Sem dúvida que bloqueie? **Perguntar antes de implementar** — nunca assumir requisito.

### Gate I — Implementação

- Mudança mínima que satisfaz a spec; convenções do repo (AGENTS.md).
- Backend + visual no **mesmo** ciclo de entrega.
- Code de produção nunca edita testes para "fazer passar" — teste errado se corrige
  com justificativa registrada.

### Gate T — Testes

- Cada critério de aceite vira asserção automatizada (suíte PHP do módulo, ou E2E
  Playwright quando envolver clique/visual).
- Regressão: rodar as suítes do módulo afetado **e** as demais (gate G2).
- Bug: teste que falha na versão antiga e passa na nova (bugfix/regressão).

### Gate Q — Gate executável (obrigatório antes de commit)

```powershell
powershell -File bin\quality-gate.ps1          # full — exigido para commit/entrega
powershell -File bin\quality-gate.ps1 -Quick   # só iteração interna (NÃO serve para commit)
```

| Estágio | O que verifica | Onde roda |
|---|---|---|
| **G0** | Containers saudáveis + **sync working tree → container** (nunca validar código que não está no container) | host (PS) |
| **G1** | `php -l` em **todo** PHP do repo (fora de vendor/data/pages/uploads) | container |
| **G2** | Suítes: smoke + social + attachments + adspy (api/discover/ui) | container |
| **G3** | JS **servido** das telas admin válido (sintaxe de todo `<script>` inline) | host (node) |
| **G4** | **Responsividade** 360/768/1280 em telas públicas+admin, viewport meta, zero `pageerror` | host (Playwright) |

- Modo full: qualquer estágio vermelho ⇒ exit 1 ⇒ **commit proibido**.
- `-Quick` roda G0+G1+G2 (sem smoke, G3, G4) — exclusivamente para loop interno.

### Gate A — Auditoria (supervisor)

- Tarefa não-trivial fecha com veredito do agente `supervisor` (read-only):
  **APROVADO** / **APROVADO COM RESSALVAS** / **REPROVADO**, item a item contra a spec,
  com evidência (saída do gate + passos).
- Máximo **3 ciclos** de correção (qa → bugfix → supervisor); depois, escala ao usuário
  com histórico — nunca entregar "pela metade" silenciosamente.

## Entrega (pós-gates)

- Commit convencional (`type(scope): título` + corpo com o "porquê"), 1 entrega = 1
  commit (ou série pequena), **push** para a branch corrente.
- Rollback = `git revert` do commit + gate full verde. Por isso o sync G0: o que está
  no container é sempre o que está no working tree.
- CI (`.github/workflows/ci.yml`) **re-executa o gate no push** — o mesmo comando que
  roda localmente roda no GitHub; merge sem CI verde não avança (branch protection é o
  próximo passo quando o repo estiver estável).

## Anti-padrões (reprovados na auditoria)

- Entregar tela "com placeholder" sem endpoint (mesmo que "depois eu ligo").
- Declarar entrega sem anexar saída do gate.
- Escrever spec/genérico com critérios do tipo "deve funcionar bem".
- Inventar caminho de UI de terceiro, escopo de API ou URL de documentação.
- Teste desativado, `skip` ou asserção removida para destravar o gate.
- `--force-recreate`/rebuild sem perceber (apaga o sync de dev) — ver AGENTS.md.
