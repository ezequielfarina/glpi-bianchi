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
