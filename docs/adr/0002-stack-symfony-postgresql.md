# ADR-0002: Stack PHP/Symfony + API Platform + PostgreSQL + Valkey

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

O time domina PHP e infraestrutura Linux, e o GLPI 12 já roda sobre kernel Symfony, o que
facilita consultar e portar lógica. A ingestão recebe rajadas: muitos agentes no mesmo horário
e payloads de até dezenas de MB.

## Decisão

| Camada | Escolha |
|---|---|
| Linguagem | PHP 8.3+ (no CT: 8.4 nativo do Debian 13) |
| Framework | Symfony 7.3 |
| API | API Platform 4 (OpenAPI automático, filtros, paginação) |
| ORM | Doctrine ORM 3 / DBAL 4 + Doctrine Migrations |
| Banco | PostgreSQL 17 (`JSONB`, `ltree`, `pg_trgm`, advisory locks) |
| Fila | Symfony Messenger sobre Valkey (Redis Streams); falhas no PostgreSQL |
| Frontend (fase 2) | Vue 3 + Vite + TypeScript, build estático |

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| Python/FastAPI | equipe e referência (GLPI) em PHP; perde a portabilidade de lógica |
| Go | ótimo para a ingestão, mas custo maior para CRUD/admin e para a equipe |
| MySQL/MariaDB | sem `ltree`, JSON com índice mais limitado e sem advisory lock por transação |

## Consequências

- O PostgreSQL vira peça central: o backup (pgBackRest/pg_dump) passa a ser crítico.
- O Valkey precisa de `maxmemory-policy noeviction` (fila não pode perder mensagem).
