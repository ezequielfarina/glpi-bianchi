# ADR-0006: Deploy em CT LXC (Proxmox) com systemd, sem Docker

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

A infraestrutura é Proxmox, e a preferência é não usar Docker. O CT LXC dá isolamento
suficiente, sobrecarga mínima, snapshot e backup nativos do PVE (vzdump/PBS).

## Decisão

- CT **Debian 13 (trixie)** unprivileged, `nesting=1`.
- Pacotes **dos repositórios oficiais**: PHP 8.4, PostgreSQL 17, Valkey 8 e nginx, sem
  repositórios de terceiros (Sury/PGDG).
- Workers do Messenger como unit template `bianchi-worker@.service` (escala com `--workers=N`).
- Provisionamento idempotente em `deploy/lxc/install.sh`; o mesmo script atualiza.

## Consequências

- Upgrade de PHP e PostgreSQL acompanha o ciclo do Debian (`apt`). Um major do PostgreSQL
  exige `pg_upgradecluster`.
- Backup recomendado: PBS do CT inteiro + `pg_dump` lógico diário (ver docs/deploy).
- Escala horizontal (vários CTs de app) exige mover o PostgreSQL e o Valkey para CTs próprios.
  O `DATABASE_URL` e o `MESSENGER_TRANSPORT_DSN` já são parametrizados.
