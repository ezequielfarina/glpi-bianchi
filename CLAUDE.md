# CLAUDE.md — instruções para agentes (banco, backend, frontend)

Projeto: **GLPI Bianchi** — inventário de ativos de TI compatível com o GLPI Agent, sem helpdesk.
Leia o [README.md](README.md) e os [ADRs](docs/adr/) antes de mudanças estruturais.

## Regras gerais (todos os agentes)

- Idioma: **código, identificadores e commits em inglês**; documentação, comentários de
  domínio e mensagens ao usuário em **português**.
- Nada de Docker: o alvo é **CT LXC Debian 13** com systemd (`deploy/lxc/`).
- Toda decisão que muda arquitetura, modelo de dados ou protocolo → novo ADR em `docs/adr/`
  (copie `docs/adr/0000-template.md`, numeração sequencial).
- Antes de concluir: `make ci` precisa passar (lint, PHPStan max, Rector, schema:validate, PHPUnit).
- Compatibilidade com o GLPI Agent é requisito: não quebrar `tests/Integration/Inventory/AgentEndpointTest.php`.
- Não copiar código do GLPI upstream sem necessidade; quando portar lógica, referencie o arquivo
  de origem no docblock (o projeto é GPLv3, compatível).

## Agente: banco

Escopo: `migrations/`, `docs/database/`, `deploy/lxc/postgresql/`, mapeamento Doctrine (atributos `#[ORM\...]`).

- PostgreSQL **17**. Extensões permitidas: `ltree`, `pg_trgm` (outras exigem ADR).
- Mudou entidade → `make diff` → **revise o SQL gerado** → commit da migration. Nunca edite migration já aplicada em produção.
- Objetos que o Doctrine não modela (triggers, índices GIN/GiST, funções) vão em migration SQL
  **e** os índices são registrados em `src/Shared/Doctrine/SchemaExtrasListener.php`.
- Toda migration precisa de `down()` funcional; o CI roda `migrate first` + `migrate`.
- Convenções: tabelas no singular snake_case; FK `<entidade>_id`; `created_at/updated_at` em
  `TIMESTAMPTZ`; JSONB para atributos variáveis; enums como `VARCHAR` (legível no Grafana).
- Atualize `docs/database/README.md` (ER e dicionário) a cada mudança de schema.

## Agente: backend

Escopo: `src/`, `config/`, `tests/`, `tools/`.

- Um diretório por módulo: `src/<Modulo>/{Entity,Enum,Repository,Service,Controller,...}`.
  Módulo novo → mapping em `config/packages/doctrine.yaml` e path em `api_platform.yaml`.
- PHP 8.3+, `declare(strict_types=1)`, classes `final`/`readonly` por padrão, sem setters
  desnecessários, construtor com os invariantes.
- Ingestão: o controller do agente **não processa** inventário — só valida, grava `raw_inventory`
  e enfileira. Processamento pesado fica no `ProcessInventoryHandler` (worker).
- Nova seção do inventário → classe em `src/Inventory/Import/Section/` implementando
  `SectionImporter` + teste de integração com fixture em `tests/Fixtures/inventory/`.
- Logs do fluxo de inventário no canal `inventory` (JSON em produção).
- API: recursos via `#[ApiResource]`; operações de escrita só quando houver caso de uso.

## Agente: frontend

Escopo: `frontend/` (+ `deploy/lxc/nginx/` para servir o build).

- Vue 3 + Vite + TypeScript (strict), consumindo **somente** a API `/api` (JSON).
- Build estático em `frontend/dist`, servido pelo nginx do CT em `/app/` — sem Node em produção.
- Não acessar banco nem rotas do agente; contratos vêm do OpenAPI (`/api/docs.json`).
- Ver `frontend/README.md` para stack, estrutura e padrões visuais.

## Comandos úteis

```bash
make help          # tudo
make ci            # pipeline completo local
make agent-sample  # simula GLPI Agent
make logs          # workers
```
