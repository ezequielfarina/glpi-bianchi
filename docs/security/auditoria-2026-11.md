# Auditoria de segurança — 2026-11

Escopo: código-fonte (backend Symfony, frontend Vue), configuração de deploy (nginx, PHP-FPM, PostgreSQL,
Valkey, systemd) e **testes de invasão contra a aplicação real rodando** (mesmo código, banco PostgreSQL,
contas Administrador / Técnico / Leitura). Ferramentas: scripts próprios (requests/Playwright), PHPUnit.

## Resumo

| Sev. | # | Achado | Situação |
|---|---|---|---|
| **Crítico** | C1 | `APP_ENV=dev` no CT expunha `/_profiler` **sem login**: `phpinfo` com `API_TOKEN`, `DATA_ENCRYPTION_KEY`, senha do banco, `APP_SECRET`; perfis com o **cookie de sessão** de quem usou o sistema — sequestro da sessão do administrador confirmado | Corrigido (3 camadas) + **girar segredos** |
| Alto | A1 | Endpoint do GLPI Agent aceitava inventário de qualquer origem — falsificação/sobrescrita de ativo pelo serial | Corrigido (redes permitidas, Basic opcional, limite) |
| Alto | A2 | Anexos (NF, contratos) e inventário bruto em claro no disco; **sem backup** | Corrigido (ADR-0019) + ZFS no host + chave `age` |
| Alto | A3 | Symfony 7.3 fora de suporte desde 01/2026 (sem correções de segurança) | **Pendente** — atualizar para 7.4 LTS |
| Alto | A4 | XSS armazenado: site do fornecedor aceitava `javascript:` e virava link | Corrigido (backend + frontend) |
| Médio | M1 | 5 senhas erradas de **qualquer** máquina travavam a conta do administrador (negação de serviço) | Corrigido (login + IP) |
| Médio | M2 | Trocar senha / desligar 2FA sem limite de tentativas (sessão roubada adivinha a senha) | Corrigido |
| Médio | M3 | JSON do painel (inclusive chave de licença revelada) ia para o cache em disco do navegador | Corrigido (`no-store`) |
| Médio | M4 | Log do PostgreSQL gravava os valores dos parâmetros (CPF/CNPJ, e-mails, payloads) | Corrigido |
| Médio | M5 | Usuário da aplicação é dono do schema: aplicação comprometida pode remover o gatilho que protege a auditoria | Recomendação |
| Médio | M6 | Agentes enviando inventário por HTTP (porta 80) — texto claro na LAN | Recomendação |
| Médio | M7 | Sem HSTS (correto com certificado auto-assinado) | Recomendação: certificado da CA interna |
| Baixo | B1 | Senha vazia → erro 500 (warning do libsodium) | Corrigido |
| Baixo | B2 | Número gigante na URL (`?branch=999…`) → erro 500 | Corrigido (400) |
| Baixo | B3 | Array no lugar de texto → 500 em contratos, NF, diagnóstico e **endpoint público do agente** | Corrigido |
| Baixo | B4 | `/api/docs` público revela os endpoints da API | Aceito (só documentação) |
| Info | I1 | `php-cs-fixer` aponta 69 arquivos antigos fora do padrão — a etapa de lint do `make ci` não passa | Observação |

## O que foi testado e resistiu

| Ataque | Resultado |
|---|---|
| SQL injection (aspas, `pg_sleep`, booleano, empilhado, NUL, `%`) em **todos** os GET × 41 parâmetros | 23.370 requisições, **0** erros 500, **0** atrasos — tudo parametrizado |
| Autorização: 300 rotas × anônimo / Leitura / Técnico | Leitura não escreve; Técnico não alcança usuários/auditoria/canais; anônimo → 401 |
| CSRF: sem `X-Requested-With`, `Origin` externo, `Origin: null`, form-urlencoded, text/plain | Todos 403 |
| Sessão: reuso após logout, fixação, flags do cookie | Token invalidado no servidor; novo token a cada login; `HttpOnly; SameSite=Strict; Secure` (HTTPS) |
| Força bruta / spraying / `X-Forwarded-For` forjado | 5 por login+IP, 30 por IP, XFF ignorado (proxy não confiável) |
| Enumeração de usuário | Mesma mensagem e custo de CPU (Argon2 fictício) |
| XXE, *billion laughs*, bomba zlib (284 KB → 300 MB), JSON com 100 mil níveis | Recusados (400) em < 0,2 s; nada vazou |
| Upload: tipo pelo conteúdo, SVG/HTML, nome com `../` | Só tipos da lista; download com `sandbox` + `nosniff` |
| Injeção de comando (ping/mtr/nmap) | argv sem shell; host validado (não aceita `-opção`) |
| Type confusion em 1.870 escritas (arrays, objetos, 1 MB, números absurdos) | Só os casos B3 (corrigidos) |
| XSS refletido/armazenado no Vue | Escape automático; tooltips do mapa escapados; único vetor era `href` (A4) |

## Correções aplicadas

- **C1** — `ProfilerGuardListener` (/_profiler, /_wdt, /_error só de loopback, em qualquer ambiente);
  nginx `return 404` nesses caminhos; `fastcgi_param APP_DEBUG 0` (o painel web nunca roda em debug,
  mesmo com `--env=dev`); profiler ligado ao `kernel.debug`. Script `deploy/lxc/rotate-secrets.sh`.
- **A1** — `AgentAccessGuard`: `AGENT_ALLOWED_NETWORKS` (padrão: redes privadas), `AGENT_BASIC_AUTH`
  (opcional, suportado pelo GLPI Agent), `AGENT_MAX_REQUESTS_PER_5MIN`.
- **A2** — ADR-0019: XChaCha20-Poly1305 com contexto e rotação de chave; anexos cifrados em fluxo com
  chave por arquivo; inventário bruto cifrado; `app:crypto:rotate`; backup diário com `age` (`deploy/lxc/backup.sh`).
- **A4** — `Payload::url()` (só http/https com host) e `safeHref()` no frontend (dados antigos também ficam inofensivos).
- **M1–M4, B1–B3** — ver CHANGELOG; cada um com teste de regressão em `tests/Integration/Security/`.

## Ações para o administrador (ordem)

1. `git pull && bash deploy/lxc/install.sh --env=dev` (aplica as correções e cifra os dados existentes).
2. **`sudo bash deploy/lxc/rotate-secrets.sh`** — o profiler esteve exposto: trate todos os segredos e sessões como vazados.
3. Troque a senha das contas de administrador; reveja `Auditoria → Acessos` atrás de logins estranhos.
4. Chave de backup: `age-keygen` **fora do CT**, chave pública em `/etc/glpi-bianchi/backup.age.pub` (docs/security/criptografia.md).
5. Criptografia do disco do CT no host Proxmox (ZFS `aes-256-gcm`) — docs/security/criptografia.md.
6. Agentes: `AGENT_BASIC_AUTH` + `user`/`password` no agent.cfg, e servidor `https://`.
7. Symfony 7.4 LTS (A3): `sed -i 's/"7\.3\.\*"/"7.4.*"/g' composer.json && composer update 'symfony/*' -W`, depois `make ci` e versionar o `composer.lock`.
8. Certificado da CA interna (AD CS / FortiGate) no nginx e então habilitar o HSTS comentado.
9. (M5) Separar papéis no PostgreSQL: dono do schema só para migrations; a aplicação com `SELECT/INSERT/UPDATE/DELETE`.
