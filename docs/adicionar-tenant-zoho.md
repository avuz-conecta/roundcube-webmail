# Adicionar um novo tenant (organização Zoho)

Passo a passo para plugar uma nova organização Zoho no webmail Avuz. Um **tenant**
= um domínio de e-mail (ex.: `empresacliente.com.br`) mapeado para uma organização
Zoho paga, com credenciais próprias de API.

O broker (`password-broker`) usa essas credenciais para: verificar login, checar se
o domínio é tenant e resetar senha no primeiro acesso. Tudo é dirigido pela variável
de ambiente **`ZOHO_TENANTS`** do serviço broker no stack.

---

## 1. Pré-requisitos

- Organização criada no **Zoho Mail Admin** (https://mailadmin.zoho.com).
- Domínio **verificado** na org (registros MX/SPF/DKIM configurados no DNS).
- Ao menos um usuário/caixa criada na org.
- Todas as contas são **org pagas** → endpoints `imappro.zoho.com` / `smtppro.zoho.com`
  (já configurado no webmail; não muda por tenant).

> **Org nova?** Antes de qualquer coisa: crie a org no Zoho Mail Admin, adicione e
> verifique o domínio, crie os usuários. Só então siga para o passo 2.

---

## 2. Gerar as credenciais de API (Self Client OAuth)

O broker autentica na API de organização do Zoho via **OAuth refresh token**. Gere
um par uma vez por organização.

### 2.1 Criar o Self Client
1. Acesse **https://api-console.zoho.com** logado como **admin da organização**.
2. **Add Client → Self Client → Create**.
3. Guarde o **Client ID** e o **Client Secret**.

### 2.2 Gerar o código com o scope certo
1. Na aba **Generate Code** do Self Client:
   - **Scope:** `ZohoMail.organization.accounts.ALL`
     (permite listar contas e resetar senha — o que o broker faz)
   - **Time Duration:** 10 min
   - **Scope Description:** `avuz-broker`
2. Selecione o **portal/org** correto e clique **Create** → copie o **grant code**
   (válido por 10 min).

### 2.3 Trocar o grant code por um refresh token
Rode (substitua os valores):

```bash
curl -s -X POST "https://accounts.zoho.com/oauth/v2/token" \
  -d "grant_type=authorization_code" \
  -d "client_id=SEU_CLIENT_ID" \
  -d "client_secret=SEU_CLIENT_SECRET" \
  -d "code=O_GRANT_CODE"
```

A resposta traz o **`refresh_token`** (não expira — guarde). O `access_token` da
resposta é descartável; o broker gera novos sozinho a partir do refresh token.

---

## 3. Descobrir o `zoid` (ID da organização)

O `zoid` identifica a org nas chamadas `mail.zoho.com/api/organization/{zoid}/...`.

- No **Zoho Mail Admin**, veja a URL após logar — contém o ID da org, **ou**
- Consulte via API com o access token do passo 2.3:

```bash
curl -s "https://mail.zoho.com/api/organization" \
  -H "Authorization: Zoho-oauthtoken SEU_ACCESS_TOKEN"
```

O campo `zoid` (ou `orgId`) da resposta é o valor a usar.

---

## 4. Montar a entrada do tenant

`ZOHO_TENANTS` é um **JSON** com chave = **domínio do e-mail** (minúsculo). Cada
domínio aponta para as credenciais da org:

```json
{
  "empresacliente.com.br": {
    "clientId": "1000.XXXXXXXX",
    "clientSecret": "abcdef...",
    "refreshToken": "1000.yyyy...",
    "zoid": "123456789",
    "forcePasswordChange": true
  }
}
```

Campos:

| Campo | Descrição |
|-------|-----------|
| chave (`empresacliente.com.br`) | domínio do e-mail do tenant, **minúsculo** |
| `clientId` / `clientSecret` | do Self Client (passo 2.1) |
| `refreshToken` | do passo 2.3 |
| `zoid` | ID da org (passo 3) |
| `forcePasswordChange` | opcional; `true` (padrão) força troca de senha no 1º login |

Para **vários tenants**, adicione mais chaves no mesmo objeto JSON — não crie uma
segunda variável:

```json
{
  "empresa-a.com.br": { "clientId": "...", "clientSecret": "...", "refreshToken": "...", "zoid": "..." },
  "empresa-b.com":    { "clientId": "...", "clientSecret": "...", "refreshToken": "...", "zoid": "..." }
}
```

---

## 5. Onde adicionar no stack

A variável vive no serviço **`password-broker`** (o app Roundcube fala com ele via
`AVUZ_BROKER_URL`).

1. **Portainer → Stacks →** stack do webmail (prod = endpoint 5).
2. Edite as **variáveis de ambiente** do serviço broker:
   - **`ZOHO_TENANTS`** = o JSON completo (todos os tenants, em uma linha).
3. Salve e **redeploy** do stack para o broker recarregar a config.

> O JSON é lido **na inicialização** do broker (`loadConfig`). Alterar a env **sem**
> recriar o container não tem efeito — force o redeploy/recreate do serviço broker.

Variáveis relacionadas do broker (não mudam por tenant):

| Variável | Papel |
|----------|-------|
| `ZOHO_TENANTS` | mapa domínio → credenciais (este doc) |
| `BROKER_SHARED_SECRET` | segredo compartilhado com o app Roundcube (`AVUZ_BROKER_SECRET`) |
| `ZOHO_IMAP_HOST` / `ZOHO_IMAP_PORT` | host IMAP que o broker usa para verificar senha (padrão `imap.zoho.com:993`) |

---

## 6. Validar

1. Confirme o JSON válido antes de subir:
   ```bash
   echo "$ZOHO_TENANTS" | jq .
   ```
2. Após o redeploy, faça **login com um usuário do novo domínio** no webmail.
3. Verifique caixa de entrada (IMAP) e envie um e-mail de teste (SMTP).
4. Se `forcePasswordChange` = true, o 1º login deve pedir troca de senha.

**Falhou o login?** Cheque, nessa ordem:
- domínio da chave = domínio do e-mail (minúsculo, sem `@`);
- refresh token válido e scope `ZohoMail.organization.accounts.ALL`;
- `zoid` correto;
- broker foi de fato recriado (env nova carregada).

---

## Notas

- O `refreshToken` **não expira**, mas é revogado se o Self Client for apagado no
  API Console. Não delete o client da org em produção.
- `clientSecret` e `refreshToken` são **segredos** — só no env do stack, nunca no git.
- O host IMAP/SMTP (`imappro`/`smtppro`) é global no webmail, não por tenant.
