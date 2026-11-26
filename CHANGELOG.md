# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · Versionamento: [SemVer](https://semver.org/lang/pt-BR/)

## [Unreleased]

### Corrigido
- MAC das interfaces lido de `mac` (formato JSON), não `macaddr`.
- Inventário parcial não apaga mais atributos ausentes do payload (ex.: `last_logged_user`).
- Migration de seed compatível com DBAL 4 (`ParameterType`); `DEFAULT_URI`; `.env.test.local` e `make`/`jq` no install.

### Adicionado
- Resultado de cada inventário (novo ativo / atualizado + nº de alterações / sem alterações).
- Forçar inventário pelo painel (agente e ativo) via `/now` do GLPI Agent, com diagnóstico (ADR-0008).
- Tela Configurações: frequência de inventário (`expiration`) e porta HTTP do agente.
- Interface web Vue 3 em `/app/`: visão geral (KPIs, inventários/hora, SOs), ativos (busca por
  nome/serial/patrimônio/UUID/MAC/IP), detalhe (hardware, software, rede, histórico, payload),
  agentes e inventários recebidos; tema escuro/claro; responsiva.
- Endpoints `/api/ui/*` com read models SQL dedicados (`src/Ui/`).
- Estrutura inicial do projeto (Symfony 7.3, API Platform 4, Doctrine ORM 3, PostgreSQL 17).
- Endpoint compatível com o GLPI Agent: `/`, `/front/inventory.php`, `/inventory`
  (PROLOG XML, CONTACT, GET_PARAMS, INVENTORY; JSON e XML legado; zlib/gzip/deflate).
- Validação do inventário contra o JSON Schema oficial (`glpi-project/inventory_format`).
- Fila assíncrona de processamento (Messenger + Valkey), retry com backoff e fila de falhas.
- Importador `Computer`: hardware/BIOS, sistema operacional, CPU, memória, discos/unidades
  ópticas, software e interfaces de rede (IPv4/IPv6).
- Inventário parcial, campos travados (`locked_field`) e histórico por campo (`asset_history`).
- Hierarquias de organização e localização em `ltree`, mantidas por trigger (com bloqueio de ciclos).
- API REST com OpenAPI em `/api/docs`, autenticação por token Bearer.
- Provisionamento de CT LXC Proxmox (Debian 13) via `deploy/lxc/install.sh`, workers em systemd.
- `Blacklist` de seriais/UUIDs/modelos/fabricantes placeholder (portada do GLPI).
- Comando `app:inventory:reprocess` (id, `--latest`, `--failed`, `--device`).
- Documentação: ADRs 0001–0006, modelo de dados, protocolo do agente, importação, deploy e API.
