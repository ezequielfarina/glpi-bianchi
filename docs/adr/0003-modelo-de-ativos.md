# ADR-0003: Tabela única de ativos com atributos em JSONB

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

O GLPI clássico usa uma tabela por tipo (`glpi_computers`, `glpi_printers`, `glpi_monitors`...)
e uma tabela por tipo de componente (`glpi_deviceprocessors` + `glpi_items_deviceprocessors`...).
O próprio GLPI 11/12 caminha para "Generic Assets" (`AssetDefinition`) justamente para fugir disso.

## Decisão

- **`asset`**: uma tabela para todos os tipos. Colunas para o que é comum e consultado
  (nome, serial, patrimônio, UUID, organização, localização, usuário, status, SO);
  `attributes JSONB` (com índice GIN) para o que é específico do tipo.
- **`asset_type`**: tipos são **dados** (seed na migration), não código.
- **`component`**: uma tabela para todos os componentes, discriminada por `kind` (enum);
  unidades normalizadas (`capacity_mib`, `frequency_mhz`), restante em `attributes`.
- **SO embutido** em `asset` (colunas `os_*`): o agente reporta um SO por máquina, e isso
  evita um JOIN em toda listagem.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Tabela por tipo (GLPI clássico) | tipagem forte por coluna | dezenas de tabelas, código duplicado, tipo novo exige migration |
| EAV puro | flexível | consulta horrível, sem tipos |
| **Tabela única + JSONB** | um CRUD, tipo novo sem código, GIN para filtrar atributos | atributos sem constraint de banco (validação na aplicação) |

## Consequências

- Relatórios em atributos específicos usam operadores JSONB (`attributes @> '{"chassis_type":"Notebook"}'`).
- Se um atributo passar a ser consultado com frequência, ele é promovido a coluna via migration.
