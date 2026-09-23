# ManyChat (parceiro) — integração com AfiliaFacil

ManyChat é o caminho para **vender no WhatsApp / Messenger / Instagram DM**. O AfiliaFacil
não cria chat nativo no MVP; a integração é feita via **templates n8n** que capturam o lead
na página e criam/atualizam o contato no ManyChat.

## O que é o ManyChat

- Automacao de mensagens em **WhatsApp, Messenger, Instagram DM**.
- Oficial Meta Business Partner (modo correto e seguro).
- Plano gratuito: ate 1.000 contatos (para testar). Plano Pro: a partir de US$ 15/mês.

## Fluxo recomendado (landing → WhatsApp)

```text
[Pagina AfiliaFacil] --(formulario: nome, email, tel)--> [POST /p/lead]
       |
       v
[n8n] -> [ManyChat: cria contato + dispara flow de boas-vindas]
```

O template pronto é `workflows/templates/captcha-leads-manychat.json`.

## Setup em 4 passos

### 1. Pegue a API Key do ManyChat

Em: ManyChat → Configuracoes → API → **Generate API Key**

Guarde essa chave (ex.: `1234567890abcdef1234567890abcdef`).

### 2. Importe o template no n8n

```text
Workflows → Import from File → workflows/templates/captcha-leads-manychat.json
```

No nó **"Cria contato no ManyChat"**, substitua `[[SUA_API_KEY]]` pela sua chave.

### 3. Ative o fluxo de boas-vindas

No ManyChat: Automation → Flows, crie o fluxo `boas-vindas` (ou o nome que você quiser)
que envia a mensagem inicial + link de compra.

Pegue o **ID do Flow** na URL: fica no final. Substitua no template.

### 4. Teste

```bash
curl -X POST http://localhost:5678/webhook/af-lead \
  -H "Content-Type: application/json" \
  -d '{"nome":"Joao","email":"joao@teste.com","phone":"5511999999999","source":"landing"}'
```

Deve aparecer no ManyChat em segundos.

## O que o AfiliaFacil entrega hoje (sem código)

1. **Pagina de captura** (Clonador com formulário)
2. **Barra de webhook**: o POST `/p/lead` envia o lead para o n8n
3. **Template n8n pronto**: 3 nodes (webhook → valida → ManyChat)
4. **Follow-up automatico**: espera 30min → fluxo de remarketing no WhatsApp

## Limites importantes

- **Não enviamos PII (email, telefone) em claro** para o ManyChat sem consentimento —
  o consentimento LGPD é coletado na página.
- **WhatsApp tem custo por conversa** (meta-initiated); o ManyChat cobra a parte.
- O AfiliaFacil **não tem acesso** às suas conversas no WhatsApp — é tudo entre você e a plataforma.

## Perguntas finduais

**Preciso do WhatsApp Business?** Recomendado (facilita templates de mensagem aprovados).

**Muitos leads de uma vez?** ManyChat tem limite de taxa (10/s). Para volume alto, ative o `wait` node (ja incluido).

**AfiliaFacil pode enviar a mensagem direto?** Planejado pós-MVP — por agora, o melhor caminho é n8n → ManyChat.

## Proximo passo

Quando o **Workfow como servico** (Fase 4) estiver ativo, os templates viram um clique no painel — sem precisar instalar n8n.
