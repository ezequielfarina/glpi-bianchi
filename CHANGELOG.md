# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · Versionamento: [SemVer](https://semver.org/lang/pt-BR/)

## [Unreleased]

### Adicionado
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
- Documentação: ADRs 0001–0006, modelo de dados, protocolo do agente, importação, deploy e API.
