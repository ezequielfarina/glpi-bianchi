# ADR-0004: Hierarquias com `ltree` mantido por trigger

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

Organizações (entidades do GLPI) e localizações são árvores. A consulta típica é "tudo
abaixo de X", tanto para visibilidade (RBAC na fase 2) quanto para relatórios. O GLPI resolve isso com
colunas `ancestors_cache`/`sons_cache` serializadas, invalidadas pela aplicação.

## Decisão

Coluna `path LTREE` (ids dos ancestrais, ex.: `1.4.17`) em `organization` e `location`:

- calculada pelo trigger `app_tree_path()` a partir de `parent_id` (BEFORE INSERT/UPDATE);
- mover um nó reescreve a subárvore (`app_tree_path_cascade()`, AFTER UPDATE);
- ciclos são bloqueados no trigger (um nó não pode virar descendente de si mesmo);
- índice GiST; consulta de subárvore: `WHERE path <@ '1.4'`;
- no Doctrine a coluna é somente leitura (`insertable: false, updatable: false, generated: ALWAYS`).

## Consequências

- A consistência fica garantida pelo banco, inclusive em alterações via SQL direto.
- Requer a extensão `ltree` (trusted no PG ≥ 13, sem precisar de superuser).
- Mover uma subárvore grande é um único UPDATE em lote, aceitável para árvores administrativas.
