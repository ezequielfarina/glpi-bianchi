# ADR-0011: Login individual com Argon2id, sessão em cookie, 2FA TOTP e perfis

- **Status:** aceito
- **Data:** 2026-11-26
- **Decisores:** Ezequiel Farina

## Contexto

O painel usava um único token estático (`API_TOKEN`) colado na tela de login e guardado no
`localStorage`. Isso trazia vários problemas:

- não havia como saber quem alterou o quê (o histórico registrava "painel");
- não era possível revogar o acesso de uma pessoa;
- todos tinham poder total;
- o token ficava acessível a qualquer JavaScript da página;
- o tráfego era HTTP.

O requisito do dono do produto: **login e senha, segurança em primeiro lugar, melhor criptografia**.

## Decisão

1. **Contas próprias do painel** (`app_account`), separadas de `app_user` (pessoas do inventário,
   criadas também pelo agente). Aparecer como "último usuário logado" não dá acesso a nada.
2. **Senha com Argon2id** (libsodium): 64 MiB, 3 iterações, sal de 128 bits.
   - O algoritmo é recomendado pela RFC 9106 e pelo OWASP.
   - Há rehash transparente no login se os parâmetros subirem.
   - Um CHECK no banco aceita só `$argon2id$`.
   - A verificação fictícia para logins inexistentes impede descobrir contas pelo tempo de resposta.
3. **Política de senha NIST SP 800-63B**:
   - comprimento mínimo de 12, sem regras de "maiúscula + símbolo";
   - recusa senhas notórias, que contenham o login ou o nome, ou com pouca variedade de caracteres;
   - recusa reutilizar a senha atual.
4. **Sessão no servidor** (`user_session`):
   - o cookie `bianchi_sid` carrega 256 bits aleatórios e é `HttpOnly`, `SameSite=Strict`,
     `Secure` (com HTTPS) e `Path=/api/ui`;
   - o banco guarda **só o SHA-256** do token;
   - a sessão expira por inatividade (30 min) e por idade (12 h), ambas configuráveis;
   - revogação imediata: logout, troca ou reset de senha, conta desativada, mudança de perfil,
     ação do administrador;
   - preferi sessão própria a JWT porque JWT não é revogável sem lista negra e o painel é single-node.
5. **2FA TOTP** (RFC 6238, implementação própria validada com os vetores da RFC):
   - opcional por conta;
   - o segredo fica cifrado com a mesma `SecretBox` das chaves de licença;
   - anti-replay por `totp_last_step`;
   - a etapa pendente (`mfa_pending`) vale 5 min, aceita até 5 códigos e não acessa nada além de `/auth/mfa`.
6. **Bloqueio progressivo** calculado sobre `auth_event`:
   - 5 falhas por login em 15 min ou 30 falhas por IP;
   - erros de 2FA contam;
   - só login completo ou desbloqueio pelo administrador zera o contador (`password_ok` não zera).
   Não usei `symfony/rate-limiter`: evita dependência nova e o mesmo dado serve de auditoria.
7. **CSRF**:
   - cookie `SameSite=Strict`;
   - toda escrita exige `X-Requested-With: XMLHttpRequest`, cabeçalho que formulário HTML não envia
     e que força preflight CORS;
   - `Origin`, quando presente, precisa ser o próprio servidor;
   - o login também é protegido.
8. **Perfis**: `admin` ⊃ `editor` (Técnico) ⊃ `viewer` (Leitura), aplicados em `security.yaml`
   (`access_control`). Revelar chave de licença exige Técnico. A interface só esconde botões; quem
   decide é o servidor.
9. **Travas**:
   - sempre resta um administrador ativo (sob `FOR UPDATE`);
   - ninguém se rebaixa, se desativa ou se exclui;
   - a senha temporária do reset é exibida uma única vez e obriga a troca;
   - o bloqueio "troca de senha obrigatória" é aplicado no servidor.
10. **Auditoria** (`auth_event` + canal de log `security`):
    - registra login, falhas com motivo interno, bloqueios, 2FA e ações administrativas;
    - nunca registra senha ou código;
    - retenção de 365 dias.
11. **HTTPS no CT**:
    - nginx com TLS 1.2/1.3, cifras AEAD com PFS e certificado auto-assinado ECDSA gerado pelo
      `install.sh` (substituível pelo da CA interna);
    - a porta 80 fica só para o GLPI Agent e redireciona o resto;
    - CSP `script-src 'self'` (o script de tema saiu do HTML inline).
12. **`API_TOKEN` continua** só para `/api/*` (integrações). Ele **não** abre o painel.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| bcrypt | universal | limite de 72 bytes, não usa memória (GPU-friendly) |
| JWT stateless | sem consulta ao banco | revogação difícil; token acessível ao JS se em storage |
| Sessão PHP nativa (arquivos) | pronta no Symfony | sem listar/revogar sessões por usuário; perde no restart do tmpfs |
| LDAP/AD já agora | senha única da empresa | depende do AD disponível; fica como fase seguinte (contas locais seguem como acesso de emergência) |
| `symfony/rate-limiter` | componente oficial | dependência nova; não gera trilha de auditoria |

## Consequências

- **Positivas:**
  - histórico com o nome de quem alterou;
  - acesso individual e revogável;
  - menor privilégio por perfil;
  - trilha de auditoria;
  - tráfego cifrado.
- **Negativas:**
  - com certificado auto-assinado, o navegador avisa até instalar um da CA interna;
  - o HSTS fica desligado até lá, porque o aviso deixaria de ter "continuar".
- **Revisitar:**
  - login via AD/LDAP ou Entra ID (OIDC) mapeando grupos para perfis;
  - exigir 2FA para administradores (configuração);
  - permissões por filial.
