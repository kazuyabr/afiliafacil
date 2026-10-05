# Guia do dono — configurar o app Meta (Facebook / Instagram)

> **Para quem é este guia:** apenas o **dono da plataforma** (você). O cliente nunca vê nada
> disto — na tela dele existe só o botão **Conectar**. As credenciais ficam no `.env` do
> servidor, e este guia é a versão completa e didática do card "Guia do dono" que aparece em
> **Integrações** no painel admin.
>
> Tempo estimado: 15–20 minutos, **uma única vez**. Depois disso, conectar (e reconectar)
> contas é só clicar em **Conectar → Entrar**.

## Visão geral (o que você vai fazer)

1. Criar um "app" no Meta (é o formulário de credenciais da Meta — não tem servidor nem código);
2. Autorizar as permissões que a plataforma usa (só para contas de teste, por enquanto);
3. Colar a URL de retorno da plataforma (redirect URI) na Meta;
4. Copiar **App ID** e **App Secret** para o `.env` da plataforma;
5. Recriar o container e testar o botão Conectar.

---

## Passo 1 — Criar o app

1. Abra <https://developers.facebook.com/apps/> e entre com a sua conta Facebook
   (a mesma que gerencia a Página onde as publicações vão sair).
2. Clique em **Criar app**.
3. Em **Use case**, escolha **"Manage everything on your Page"**
   (em português: *"Gerenciar tudo da sua Página"*).
   - Se aparecer a escolha do **tipo de app**, escolha algo genérico como **"Something else"**.
   - ⚠️ **Nunca escolha "Consumer"**: apps Consumer **bloqueiam os escopos `pages_*`** e o login
     falha com o erro *"Invalid Scopes: pages_manage_posts"*.
4. Dê um nome (ex.: *AfiliaFacil*) e confirme. O app já nasce em modo **Development** — é
   exatamente o que queremos agora.

## Passo 2 — Liberar as permissões (Use Cases)

Apps criados hoje **não têm o menu "Add Product"** antigo: tudo passa pelo menu lateral
**Use Cases** (Casos de uso).

1. No menu lateral, abra **Use Cases** → clique no caso de uso do seu app → **Customize**.
2. Vá em **Permissions and features** e garanta o status **"Ready for testing"** em:
   - `pages_show_list`
   - `pages_read_engagement`
   - `pages_manage_posts`
   - `instagram_basic` *(só se for usar Instagram)*
   - `instagram_content_publish` *(só se for usar Instagram)*
   - Se uma permissão não estiver na lista: clique em **Actions → Add to use case**.
3. Para Instagram, adicione também o produto **Instagram Graph API** (se aparecer a seção de
   produtos/use cases para isso).

> Se o popup de login reclamar **"Invalid Scopes: ..."** depois, volte aqui: quase sempre é
> uma dessas permissões sem "Ready for testing", ou o app está no tipo **Consumer**.

## Passo 3 — Cadastrar o Redirect URI (URL de retorno)

A Meta só devolve o login para uma URL previamente cadastrada. A sua é
**`<endereço-do-painel>/admin/api/social.php?action=callback`** — ou seja, o mesmo domínio/ porta
que você usa para abrir o painel, mais esse caminho. Exemplos:

```
http://localhost:9876/admin/api/social.php?action=callback        ← painel em localhost
https://seusite.com/admin/api/social.php?action=callback          ← produção
```

> **Copie em vez de digitar**: a URL exata aparece no card "Guia do dono" (Integrações) e nos
> passos do modal **Conectar** do admin. Ela precisa bater **caractere por caractere** —
> `http` ≠ `https`, `localhost` ≠ `127.0.0.1`. Se você abrir o painel por outro endereço
> (tunel/Cloudflare), cadastre a URL desse endereço também.

**Onde fica o campo `Valid OAuth Redirect URIs`** — depende do layout do seu app; é **um** destes:

| # | Caminho |
|---|---------|
| a | Menu lateral **Use Cases** → no caso de uso → **Customize** → aba **Settings** → **Valid OAuth Redirect URIs** (dashboard novo; apps por use case **não têm "Add Product"**) |
| b | Menu lateral **Facebook Login for Business** → **Settings** → **Valid OAuth Redirect URIs** |
| c | Menu lateral **Add Product** → **Facebook Login** → **Set Up** → **Facebook Login → Settings → Client OAuth Settings** (layout antigo) |

**Atalho:** abra no navegador `https://developers.facebook.com/apps/SEU_APP_ID/fb-login/settings/`
(troque `SEU_APP_ID`). Se a página abrir, é lá que você cola.

> Falta isso e o login é recusado com **"URL bloqueada"** (*URL Blocked*).

No layout **(c)** a mesma tela (**Client OAuth Settings**) tem mais dois toggles:

- **"Forçar HTTPS" fica travado em `Sim`** — é regra da Meta desde 2018 e **não dá para
  desmarcar** (nem precisa): em **modo Development** o redirect `http://localhost` é aceito
  automaticamente (é o que o tooltip do próprio campo diz). Ou seja: **mantenha o app não
  publicado** durante os testes locais; em produção o painel roda em `https://` e tudo bate.
- **"Usar modo estrito para URIs de redirecionamento"** pode ficar **`Sim`**: ele exige match
  exato, e a sua URI bate caractere por caractere.
- O **"Validador da URI"** no topo é só uma ferramenta de teste (o X vermelho sobre o exemplo
  `https://example.com/oauth.php` não é erro da sua configuração).

## Passo 4 — App ID, App Secret e dados do app

1. Menu lateral → **Settings → Basic** — em português: **Configurações do app → Básico**.
   É a seção própria do app, lá embaixo no menu lateral (a mesma onde fica "Publicar") —
   **não** é o "Configurações" do submenu "Login do Facebook", que é a tela do Passo 3.
   Atalho no navegador: `https://developers.facebook.com/apps/SEU_APP_ID/settings/basic/`
   (troque `SEU_APP_ID`).
2. Copie o **App ID** e o **App Secret** (botão **Mostrar** ao lado do secret).
3. Preencha também:
   - **App Domains**: **deixe vazio em desenvolvimento** — o campo exige domínio com TLD
     (`.com`, `.org`) e **`localhost` não é aceito** (aparece erro vermelho). Ele **não afeta**
     o login nem o redirect; preencha só quando tiver o domínio de produção (ex.: `seudominio.com`).
   - **Site URL**: `http://localhost:9876` — se não achar o campo, clique em
     **Add Platform** / **Adicionar plataforma** (fim da página) → **Website** e cadastre lá
   - **Categoria**: qualquer uma (ex.: *Business and pages*)
   - **Privacy Policy URL**: `http://localhost:9876/privacidade`
4. Clique em **Save Changes**.
   - ⚠️ Sem **Categoria** e **Privacy Policy** o Meta **ignora o save em silêncio** e depois o
     login falha com *"o domínio não está incluído nos domínios do app"*.

## Passo 5 — Colar no `.env` e recriar o container

No arquivo `.env` da raiz do projeto:

```env
META_APP_ID=cole_aqui_o_app_id
META_APP_SECRET=cole_aqui_o_app_secret
```

Depois **recrie** o container (um simples restart **não** recarrega o `.env`):

```powershell
docker-compose up -d --force-recreate
```

> O `.env` é gitignored — nunca vai para o repositório. Cliente nenhum tem acesso a ele.

## Passo 6 — Testar

1. Painel → **Integrações** → em qualquer rede, clique em **Conectar** → a janela oficial do
   Facebook abre **direto** (o mesmo fluxo do cliente, sem etapa intermediária). O modal com os
   pré-requisitos + **Configuração avançada** só aparece se faltar credencial — o guia completo
   está no card **Guia do dono** nesta mesma tela.
2. Faça login → **Continuar**.
   - **Instagram**: o popup é o do **Facebook mesmo** (é assim que a API do IG funciona —
     o login autentica pelo Facebook e a sua conta profissional vem da Página ligada a ela).
     Depois de conectar, a conta que aparece é a do Instagram.
3. Pronto: a conta aparece conectada e já dá para publicar em **Criar → Publicações**.

### Quem pode conectar em modo Development?

Em **Development**, só contas com **papel no app** conectam — quem criou o app já é admin, ou
seja: **você conecta agora, sem App Review**. Para clientes/usuários comuns:

- temporariamente: adicione-os como **testers** em **App Roles** do app; ou
- definitivamente: coloque o app **Live** e faça a **App Review** (ver abaixo).

A conta do Facebook precisa ter uma **Página** própria (<https://www.facebook.com/pages/create> —
publicamos na Página; Business Suite não é obrigatório). No Instagram, a conta precisa ser
**profissional** (Creator/Business) conectada à sua Página.

---

## App Review (quando os clientes forem conectar com o app da plataforma)

Checklist Meta:

1. App no modo **Live**;
2. **Verificação de negócio** (documentos da empresa);
3. Produtos **Facebook Login** + **Instagram Graph API**;
4. Permissões: `pages_show_list`, `pages_read_engagement`, `pages_manage_posts`,
   `instagram_content_publish`;
5. Política de privacidade/URL validada;
6. Demo do fluxo de login + publicação.

Enquanto isso, cada cliente também pode criar o **próprio app** e salvá-lo na
**Configuração avançada** da tela de Integrações (App ID/Secret por usuário, criptografados) —
o criador do app é admin dele, então funciona **antes** da App Review.

---

## Erros comuns → causa → solução

| Erro / sintoma | Causa provável | Solução |
|---|---|---|
| `Invalid Scopes: pages_manage_posts` (ou `instagram_*`) | Permissão sem "Ready for testing", ou app tipo Consumer | Passo 2 (Use Cases) e/ou trocar tipo para "Something else" |
| `URL bloqueada` (URL Blocked) | Redirect URI não cadastrado | Passo 3 (os 3 locais + atalho da URL) |
| Redirect `http://localhost` recusado | App **publicado** (Live) — `http://localhost` só é aceito em Development | Deixe o app **não publicado** durante os testes locais (o toggle "Forçar HTTPS" fica travado em Sim, mas em Development o localhost é aceito mesmo assim) |
| `Missing client_id parameter` | Parâmetros da troca de token não chegando ao Meta (versão antiga da plataforma enviava GET no corpo) — ou `META_APP_ID` vazio | Atualize a plataforma (corrigido) e confira `META_APP_ID`/`META_APP_SECRET` no `.env` + `--force-recreate` |
| "o domínio não está incluído nos domínios do app" | Save de Settings ignorado (Categoria/Privacy vazios) | Passo 4 → preencher tudo e **Save Changes** |
| Login abre e volta pedindo de novo (loop de consentimento) | Consentimento antigo revogado/resetado | <https://www.facebook.com/settings?tab=applications> → remova o app e repita o login |
| Popup não abre / botão sem reação no cliente | App sem credencial configurada (só afeta clientes) | `META_APP_ID/SECRET` no `.env` + recriar container |
| Funciona só pra você | Modo Development (papéis do app) | Testers ou App Review |
| Token "expira" antes do agendamento | Token de 1 hora do Explorer | Use o login oficial (o sistema converte para token long-lived) ou cole o token long-lived (60 dias) do Access Token Debugger |

## Outras redes (mesmo padrão, outros envs)

| Rede | Variáveis no `.env` | Redirect URI |
|---|---|---|
| Threads | `THREADS_APP_ID`, `THREADS_APP_SECRET` | `…/admin/api/social.php?action=callback` |
| X (Twitter) | `X_CLIENT_ID`, `X_CLIENT_SECRET` | idem (lembrar: X exige créditos em Billing desde 06/2026) |
| TikTok | `TIKTOK_CLIENT_KEY`, `TIKTOK_CLIENT_SECRET` | idem (+ domínio em *URL Configuration*) |

Todas seguem o mesmo fluxo: **criar app → cadastrar redirect → colar no `.env` → recriar
container → Conectar**. O modal do painel tem um guia por rede (visível só para admin).

## Segurança

- `META_APP_SECRET` é um **segredo**: só no `.env`, nunca em repositório, print ou mensagem.
- Tokens de acesso das contas ficam **criptografados** no banco e nunca aparecem na tela.
- Para revogar acesso: remova o app em
  <https://www.facebook.com/settings?tab=applications> e clique em **Desconectar** no painel.
