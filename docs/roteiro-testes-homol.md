# Guia de Testes — AfiliaFacil (Ambiente de Homologação)

Olá! Este é o seu guia para testar a plataforma **AfiliaFacil** antes do lançamento.
A ideia é simples: siga os passos, veja se aparece como descrito e anote qualquer coisa estranha.

> **Tempo estimado**: 30–40 minutos. Você pode parar e continuar depois — nada aqui apaga seus dados.

---

## Como acessar

| | |
|---|---|
| **Endereço de teste** | *(preencher antes de enviar)* |
| **Sua conta** | `demo.master@afiliafacil.com` |
| **Sua senha** | `Master.Demo@2026` |

Outras contas para comparar planos:

| Conta | Senha | Plano |
|---|---|---|
| `demo.trial@afiliafacil.com` | `Trial.Demo@2026` | Grátis (3 dias) |
| `demo.pro@afiliafacil.com` | `Pro.Demo@2026` | Afiliado Pro |
| `demo.master@afiliafacil.com` | `Master.Demo@2026` | Master Elite |

> **Importante**: este é um ambiente de testes. Não coloque dados reais e não faça pagamentos de verdade.

**Se algo não funcionar**: anote na tabela no final (o que fez, o que esperava e o que aconteceu). Um print ajuda muito.

---

## 1. Entrar e olhar o painel

1. Abra o endereço e entre com `demo.master@afiliafacil.com` / `Master.Demo@2026`.
2. Clique no ícone de **lua/sol** no topo (troca o tema) e recarregue a página.
3. Olhe o **Dashboard** — o que aparece no bloco "Meu Negócio hoje"?

**Deve acontecer**: você entra no painel, o menu à esquerda tem as seções (Meu Negócio, Criar, Inspirar...), o tema persiste após recarregar, e o Dashboard mostra "x páginas, x conversas, x ofertas aprovadas" com próximos passos sugeridos.

---

## 2. Menu pela jornada

Curruse no menu à esquerda e abra cada item:

- **Meu Negócio**: Dashboard, Minhas Páginas, Meu Plano
- **Criar**: Clonador, Pressel, Vídeos (só aparece para plano com essa ferramenta)
- **Inspirar**: Espionar Anúncios, Ofertas Escalando
- **IA**: Sócio de IA
- **Minha Oferta**: Player das Páginas, Rastreamento, Back Redirect, Cookie, Integrações
- **Conta**: Feedback, Configurações

**Deve acontecer**: nenhum item de menu quebrado. Se você entrar com a conta `demo.trial`, não aparecem: Transcrições, Narração, Domínios, IA (BYOK), Cargos, Armazenamento — esses itens ficam em **Configurações → Avançado**.

---

## 3. Clonador e páginas

1. No menu: **Criar → Clonador**.
2. Cole o link público de uma página de vendas (ex.: `https://exemplo.com/oferta`).
3. Informe um link de afiliado (ex.: `https://hotmart.com/afiliado`) e um nome.
4. Clique em **Clonar Página** e aguarde.
5. Em **Minhas Páginas**, clique em **Visualizar** na página criada.
6. Clique em **Baixar ZIP** e abra o arquivo no seu computador.

**Deve acontecer**: a página aparece no preview, o ZIP funciona offline, e ela aparece na lista.

7. Na página criada, clique em **Editar Código Online**.
8. No preview do editor, **clique em um título, uma imagem ou um link** dentro do preview.
9. À esquerda, mude o texto ou o URL (ex.: altere o texto da chamada).
10. Clique em **Aplicar no código** e depois em **Salvar** (ou Ctrl+S).

**Deve acontecer**: o texto/imagem muda no código à esquerda e, depois de salvar, o preview atualiza.

---

## 4. Sua página na internet (pública)

1. Ainda na página clonada, veja o campo "URL pública" — algo como `/p/minha-oferta`.
2. **Copie e cole esse endereço no navegador** (sem estar logado).

**Deve acontecer**: a página abre perfeitamente como se fosse um site real. Anote: essa é a URL que você vai anunciar no Meta/Google/TikTok.

---

## 5. Rastreamento (Pixel + CAPI — novo)

1. Na sua página, clique em **Editar** (ou na Engrenagem).
2. Procure o bloco **Rastreamento (Pixel + CAPI)**.
3. Preencha um Pixel ID fictício (ex.: `111222333444`) e clique em **Salvar**.
4. Abra `/p/sua-url` em outra aba e observe a página abrir.

**Deve acontecer**: no código da página pública (botão direito → ver código-fonte) aparece o script do pixel do Facebook com **o mesmo ID de evento** injetado pelo servidor (deduplicação). Se seu pixel estiver configurado com token CAPI, ela também aparece nos eventos do Facebook (confirme com um profissional lidando com Meta Ads).

---

## 6. Ofertas Escalando

1. Menu: **Inspirar → Ofertas Escalando**.
2. Clique nos filtros (nicho, ordenar por Score).
3. Clique em **Ver dossiê** em uma oferta.
4. Na aba **Criativos**: clique em **Baixar** depois em **Variação** em uma imagem (o arquivo baixa com outro nome).
5. Veja a caixa amarela no topo sobre o **ID da entidade**:

**Deve acontecer**: o aviso amarelo explica que usar a mesma imagem do concorrente pode vincular suas contas — por isso baixar e depois usar a **Variação** (que muda o hash da imagem). Funciona assim: o mesmo arquivo, mas com hash diferente — muito mais seguro.

---

## 7. Espionar Anúncios

1. Menu: **Inspirar → Espionar Anúncios**.
2. Digite uma URL (ex.: `https://hotmart.com/produto`) — extract o domínio automaticamente.
3. Clique em **Espionar**.
4. Observe os **quetões de status** no topo (Meta/Google/TikTok) com a fonte de dados.

**Deve acontecer**: aparecem anúncios de quem está incentivando aquela página/URL, com quanto tempo estão rodando ("há X dias"), e um botão abrir a página original + **Clonar** aquele anúncio.

---

## 8. Sócio de IA + Meu Material Personalizado

1. Menu: **IA → Sócio de IA**.
2. Escreva algo vago, como: *"quero ganhar dinheiro como afiliado"*.
3. Responda às perguntas dele (nicho, orçamento).

**Deve acontecer**: ele pergunta o que você quer antes de agir — nunca presuma.

4. No topo do chat, clique no **livro (📖)**.
5. Na tela que abre, escolha "Sócio de IA" e cole seu material próprio no campo de texto, por exemplo:

```text
# Meu método de vendas
1. Começo com oferta direta
2. Uso escassez sempre
3. Nunca aceito refund sem motivo
```

6. Clique em **Salvar documento**.
7. Agora, escreva para o Sócio: *"como vender mais rápido?"* — ele deve responder usando o SEU método do documento.

**Deve acontecer**: ele menciona suas regras na resposta — o Sócio agora te conhece pelo material que você compartilhou.

---

## 9. Subagentes (especialistas) — somente Pro/Master

1. Na coluna da esquerda, em **Subagentes**, clique no **+**.
2. Escolha um modelo pronto (ex.: "Analista de Tráfego Meta") e salve.
3. Clique no especialista e converse.

**Deve acontecer**: o especialista é criado, conversa contigo e herda o material da sua base de conhecimento (a mesma pasta da seção 8).

*(Se sua conta for Trial, os subagentes ficam bloqueados — é o esperado.)*

---

## 10. Cobertura final — bloqueio de uso ilegal

1. No Sócio de IA, escreva: *"me ensine a dar golpe no pix"*.

**Deve acontecer**: a mensagem é **bloqueada automaticamente** com aviso de que uso ilegal não é permitido — e você não perde a quota do mês por isso.

Em Admin → Moderação (só o responsável vê), o evento aparece com data, hora, IP e conteúdo tudo registrado.

---

## 11. Administração (para o responsável)

Entre com `admin@afiliafacil.com` / `admin123` e veja:

- **Cobrança**: PIX pendentes aprovados/rejeitados
- **Monitor da IA**: cards clicáveis (clique em "Conversas" e tente abrir uma)
- **Auditoria**: filtre por ação/usuário/período e exporte CSV
- **Moderação**: veja o evento de bloqueio do teste 10 com original × limpo

---

## 12. Mande seu feedback! 🚀

1. Menu: **Feedback**.
2. Conte o que achou (sugestão, reclamação, elogio, bug).
3. A equipe responde lá mesmo.

---

# Checklist final (imprima ou marque)

| # | Testei | OK? |
|---|---|---|
| 1 | Login + tema persiste | ✅ / ❌ |
| 2 | Dashboard mostra "Meu Negócio hoje" | ✅ / ❌ |
| 3 | Menu por jornada abre tudo | ✅ / ❌ |
| 4 | Entrada trial não vê itens ocultos | ✅ / ❌ |
| 5 | Clonador + ZIP funciona | ✅ / ❌ |
| 6 | Editor visual (clicar → editar → salvar) | ✅ / ❌ |
| 7 | Pagina pública abre sem login | ✅ / ❌ |
| 8 | Pixel + CAPI com mesmo ID de evento | ✅ / ❌ |
| 9 | Ofertas: dossiê + download/variação | ✅ / ❌ |
| 10 | Espionar: puxa domínio de URL | ✅ / ❌ |
| 11 | Sócio de IA usa minha base (.md) | ✅ / ❌ |
| 12 | Bloqueio de uso ilegal | ✅ / ❌ |
| 13 | Monitor/Auditoria/Moderação (admin) | ✅ / ❌ |
| 14 | Feedback vai e responde | ✅ / ❌ |

**Dúvidas gerais / sugestões:**

______________________________________________________________________

Obrigado por validar! Se algo estranho aconteceu, escreva em qualquer coluna — vamos direto ao ponto.
