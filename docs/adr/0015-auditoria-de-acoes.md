# ADR-0015: Trilha de auditoria de todas as ações da interface, somente inclusão

- **Status:** aceito
- **Data:** 2026-11-27
- **Decisores:** Ezequiel Farina

## Contexto

O sistema já registra acessos em `auth_event` (ADR-0011) e o histórico de campos de cada ativo em
`asset_history`, mas não havia registro de *quem fez o quê* no restante: cadastros, exclusões, movimentações de
estoque, notas fiscais, varreduras, canais de notificação, configurações. O usuário pediu auditoria de tudo que é
feito no sistema.

## Decisão

1. **Tabela `audit_log`** (uma linha por ação): quando, conta, usuário, IP, ação (`invoice.deleted`,
   `stock.out`…), módulo, método e caminho HTTP, tipo/id do objeto, **descrição legível do objeto**
   (`NF 4512/1 — Kalunga`, `BIA-000058 — Monitor 24"`), status HTTP e `details` (JSONB) com o corpo da
   requisição **sem segredos** e os ids devolvidos.
2. **Captura central, não espalhada pelos controllers:** `AuditSubscriber` (eventos `kernel.controller` e
   `kernel.response`) audita **toda escrita em `/api/ui`** (POST/PUT/PATCH/DELETE) e as leituras sensíveis do
   catálogo (exibição da chave de licença). Rota nova entra automaticamente — sem catálogo ela é gravada com a
   chave genérica (nome da rota, módulo "Outros"); o catálogo (`AuditCatalog`) só dá nome bonito e objeto.
3. **Objeto lido antes de executar:** a descrição é consultada no `kernel.controller`, então uma exclusão guarda o
   nome do que foi apagado. Criação pega o id da resposta.
4. **Só ação bem-sucedida (2xx) é gravada.** Validação recusada (422) e permissão negada (403) não aconteceram;
   tentativas de acesso continuam em `auth_event` e no log.
5. **Segredos nunca vão para a trilha:** chaves com `pass|secret|token|totp|otp|code|community|license_key|key|
   api_key|chat_id|webhook` viram `***`; em canais de notificação o `target` (URL/token) também. Arquivos enviados
   viram `{nome, tamanho, tipo}`. `details` limitado a 16 KB.
6. **Somente inclusão no banco:** trigger `trg_audit_log_guard` bloqueia UPDATE e DELETE (inclusive de quem tem
   acesso ao banco pela aplicação). Única exceção: o `SET NULL` da FK quando a conta é excluída (o nome do usuário
   continua em `actor`). Sem retenção automática.
7. **Fora da trilha:** login/2FA/logout (já em `auth_event`), ingestão do GLPI Agent e processos de fundo
   (varredura agendada, tick) — não são ações de uma pessoa na interface.
8. **Consulta:** `GET /api/ui/audit/actions` e tela *Auditoria → Ações no sistema* (só Administrador), com filtro por
   módulo, usuário, período e busca no objeto/detalhes.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Chamada de auditoria em cada controller/serviço | descrição sob medida | fácil esquecer em rota nova; dezenas de pontos a manter |
| Triggers de banco em todas as tabelas | pega até escrita fora da aplicação | não sabe quem é o usuário da interface nem o "porquê"; volume grande (ex.: inventário do agente) |
| Doctrine lifecycle listeners | diff por campo | a escrita da interface é majoritariamente DBAL (sem UnitOfWork); não cobre ferramentas |
| `pgaudit` | auditoria no nível do SQL | não identifica o usuário da aplicação; operação e leitura difíceis |

## Consequências

- Positivas: rastreabilidade completa e uniforme; impossível apagar ou alterar a trilha pela aplicação.
- Negativas / dívidas: o registro mostra o que foi **enviado**, não um diff "antes → depois" por campo (o ativo
  continua tendo `asset_history` para isso); a tabela cresce sem limite (estimativa: poucos MB/ano).
- Revisitar: diff por campo nos cadastros mais sensíveis; exportação CSV; retenção configurável (exigiria uma
  função de expurgo autorizada no trigger).
