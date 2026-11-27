# API REST

API Platform 4 · JSON-LD (Hydra) ou JSON · OpenAPI: `GET /api/docs.json` · UI: `/api/docs`

## Autenticação

Token Bearer estático no MVP (`API_TOKEN` em `.env.local`, gerado pelo `install.sh`):

```bash
TOKEN=$(sed -n 's/^API_TOKEN=//p' /opt/glpi-bianchi/.env.local)
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' http://<ct>/api/assets
```
Token vazio = API desabilitada. Fase 2: OIDC (JWT) com RBAC por organização.

## Recursos

| Recurso | Operações | Filtros |
|---|---|---|
| `/api/assets` | GET, GET/{id}, POST, PATCH | `name` (ipartial), `serialNumber`, `inventoryNumber`, `hardwareUuid`, `assetType.code`, `organization`, `location`, `status`, `lastInventoryAt[after]`, `order[...]` |
| `/api/components` | GET | — |
| `/api/software` | GET | `name`, `publisher` |
| `/api/software_versions`, `/api/software_installations` | GET | — |
| `/api/network_ports` | GET | `macAddress`, `asset` |
| `/api/ip_addresses`, `/api/vlans` | GET (+POST vlans) | — |
| `/api/agents` | GET | `deviceId`, `name` |
| `/api/raw_inventories` | GET (sem payload) | `deviceId`, `status` |
| `/api/organizations`, `/api/locations` | GET, POST, PATCH | — |
| `/api/asset_types`, `/api/asset_models`, `/api/manufacturers` | GET | — |
| `/api/locked_fields` | GET, POST, DELETE | — |
| `/api/asset_histories` | GET | — |

Paginação: `?page=2&itemsPerPage=100` (máximo 500).

### Matriz e filiais (`/api/ui`, mesmo token)

| Método | Rota | Uso |
|---|---|---|
| GET | `/api/ui/branches` · `/api/ui/branches/{id}` | lista (com contagens) / detalhe (ativos por tipo, anexos) |
| POST · PUT | `/api/ui/branches` · `/api/ui/branches/{id}` | `name`, `code`, `kind` (`headquarters`/`branch`), `tax_id`, endereço, `latitude`/`longitude`, `networks` (lista ou texto, CIDR). Resposta: `{id, assigned}` (`assigned` = ativos vinculados pela rede) |
| DELETE | `/api/ui/branches/{id}` | ativos ficam sem filial |
| POST | `/api/ui/branches/{id}/assets` | `{"assets": [uuid, …]}` move em lote; `{id}` = `0` remove o vínculo |
| GET | `/api/ui/assets?branch={id}` · `?branch=none` | ativos da filial / sem filial |
| GET | `/api/ui/assets?stock=all` · `?stock=only` | por padrão a lista **não** traz equipamentos guardados no estoque (`status=in_stock`); `all` inclui, `only` só eles. Sem o parâmetro, a resposta traz `in_stock_matches` |

### Estoque e notas fiscais (`/api/ui/stock`, mesmo token — ADR-0014)

| Método | Rota | Uso |
|---|---|---|
| GET | `/api/ui/stock/summary` · `/low-count` | resumo (itens, valor, abaixo do mínimo, notas a enviar, categorias, setores) · nº abaixo do mínimo |
| GET · POST | `/api/ui/stock/items` | lista (`q`, `category`, `low=1`, `inactive=1`) · cria `{name, category, min_quantity, tracked, type}` |
| GET · PUT · DELETE | `/api/ui/stock/items/{id}` | detalhe (unidades em estoque, movimentações, consumo) · altera · exclui (sem histórico) ou desativa (saldo zero) |
| POST | `/api/ui/stock/in` | entrada manual `{reason, occurred_at?, lines: [{stock_item_id, quantity, unit_cost?, serials?}]}` |
| POST | `/api/ui/stock/out` | saída `{branch_id, department?, recipient?, occurred_at?, notes?, lines: [{stock_item_id, quantity} ou {stock_item_id, assets: [uuid]}]}` → `{batch}` |
| GET | `/api/ui/stock/shipments/{batch}` | dados da etiqueta de envio de uma saída |
| GET | `/api/ui/stock/shipments/{batch}/label.odt` | etiqueta em .odt (modelo Entrega_TI) — `sender`, `destination`, `department`, `attention` opcionais sobrescrevem os dados da saída |
| POST | `/api/ui/stock/adjust` | `{stock_item_id, quantity, reason}` ou `{stock_item_id, assets, asset_status, reason}` |
| GET | `/api/ui/stock/movements` · `/consumption` | razão (`item`, `branch`, `kind`, `from`, `to`) · consumo por filial |
| GET · POST | `/api/ui/stock/invoices` | lista (`status`, `q`, `supplier`) · registra `{supplier_id, number, series?, issue_date, received_at?, total, notes?, items: [{stock_item_id, quantity, unit_price, serials?}]}` |
| GET · PUT · DELETE | `/api/ui/stock/invoices/{id}` | detalhe · altera cabeçalho · exclui (se nada saiu do estoque) |
| POST | `/api/ui/stock/invoices/{id}/status` | `{status: "registered"}` ou `{status: "sent"}` |

### Auditoria (`/api/ui/audit`, só Administrador — ADR-0015)

| Método | Rota | Uso |
|---|---|---|
| GET | `/api/ui/audit/actions` | trilha de ações: `q`, `module`, `actor`, `entity_type`, `entity_id`, `from`, `to`, `page` (50 por página) |
| GET | `/api/ui/audit` | acessos (`auth_event`): `q`, `event`, `page` |

Toda escrita feita pela API com token também entra na trilha, com o nome do token/usuário em `actor`.

## Exemplos

```bash
# Notebooks Dell sem inventário desde outubro
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  'http://<ct>/api/assets?assetType.code=computer&name=nb-&lastInventoryAt[before]=2026-10-01'

# Quem tem este MAC?
curl -s -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
  'http://<ct>/api/network_ports?macAddress=b0:26:28:aa:bb:01'

# Travar o nome de um ativo (o inventário não sobrescreve mais)
curl -s -X POST -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/ld+json' \
  -d '{"field":"name","asset":"/api/assets/42"}' http://<ct>/api/locked_fields
```
