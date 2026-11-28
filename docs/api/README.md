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
| POST | `/api/ui/stock/shipments/{batch}/print` | imprime direto na impressora de etiquetas: `{volumes, sender?, destination?, department?, attention?}` → `{printed, printer, bytes, ms}`; 422 com `errors.printer` se a impressora não responder |
| GET | `/api/ui/stock/label/preview.png` | prévia antes de registrar a saída: `destination`, `department`, `attention`, `sender`, `volumes` |
| GET | `/api/ui/stock/shipments/{batch}/label.png` | prévia exata (PNG 1 bit) do que vai para a impressora (mesmos parâmetros, na query) |
| GET · POST | `/api/ui/stock/label-printer` · `/label-printer/test` | configuração da impressora de etiquetas · etiqueta de teste |
| GET | `/api/ui/stock/shipments/{batch}/label.odt` | etiqueta em .odt (modelo Entrega_TI) — `sender`, `destination`, `department`, `attention` opcionais sobrescrevem os dados da saída |
| POST | `/api/ui/stock/adjust` | `{stock_item_id, quantity, reason}` ou `{stock_item_id, assets, asset_status, reason}` |
| GET | `/api/ui/stock/movements` · `/consumption` | razão (`item`, `branch`, `kind`, `from`, `to`) · consumo por filial |
| GET · POST | `/api/ui/stock/invoices` | lista (`status`, `q`, `supplier`) · registra `{supplier_id, number, series?, issue_date, received_at?, total, notes?, items: [{stock_item_id, quantity, unit_price, serials?}]}` |
| GET · PUT · DELETE | `/api/ui/stock/invoices/{id}` | detalhe · altera cabeçalho · exclui (se nada saiu do estoque) |
| POST | `/api/ui/stock/invoices/{id}/status` | `{status: "registered"}` ou `{status: "sent"}` |

### Infraestrutura, cabeamento, circuitos e IPAM (ADR-0016)

Leitura para qualquer perfil; escrita para Técnico/Administrador; toda escrita entra na auditoria. Listas devolvem `{items: [...]}`; criação devolve `201 {id}`.

| Método | Rota | Uso |
|---|---|---|
| GET | `/api/ui/dcim/choices` | opções (tipos de porta e físicos, tipos/cores de cabo, status, compatibilidade, funções) |
| GET · POST · PUT · DELETE | `/api/ui/dcim/locations[/{id}]` | árvore (`branch`) · detalhe (racks, equipamentos, ativos, fotos) · `{name, kind, branch_id \| parent_id, code, description}` |
| GET · POST · PUT · DELETE | `/api/ui/dcim/racks[/{id}]` | lista (`branch`, `location`, `q`) · detalhe com `devices` (elevação) e `unracked` |
| GET · POST · PUT · DELETE | `/api/ui/dcim/roles[/{id}]` · `/device-types[/{id}]` | funções · tipos (com `templates`) |
| POST · PUT · DELETE | `/api/ui/dcim/device-types/{id}/templates` · `/port-templates/{id}` | portas do modelo em lote: `{kind, name: "Gi1/0/[1-48]", type, …}`; `kind: "pair"` cria frontal+traseira mapeadas |
| GET · POST · PUT · DELETE | `/api/ui/dcim/devices[/{id}]` | lista (`q`, `branch`, `location`, `rack`, `role`, `type`, `status`) · detalhe com portas, *link peers*, destino do caminho, IPs, energia · `{name, device_type_id, role_id?, branch_id, location_id?, rack_id?, position?, face?, asset_uuid?, copy_interfaces?}` |
| GET | `/api/ui/dcim/asset-candidates` · `/assets/{uuid}/device` | ativos sem equipamento (`q`) · posição física e conexões do ativo |
| GET · POST | `/api/ui/dcim/devices/{id}/ports` | portas para seletores (`kind`, `free=1`) · cria em lote |
| PUT · DELETE · POST | `/api/ui/dcim/ports/{id}` · `/ports/delete` | altera (VLANs: `mode`, `untagged_vlan_id`, `tagged_vlan_ids`; mapeamento: `rear_port_id`, `rear_position`) · exclui · exclui em lote `{ids}` |
| GET | `/api/ui/dcim/ports/{id}/trace` · `/circuit-terminations/{id}/trace` | caminho: `{hops: [{t:"nodes"}, {t:"cable"}…], complete, active, split, ends, cables, length_m, bidirectional}` |
| GET · POST · PUT · DELETE | `/api/ui/dcim/cables[/{id}]` | lista (`q`, `branch`, `device`, `type`, `status`) · `{label, type, status, color, length, length_unit, a_terminations: [{type: "port"\|"circuit", id}], b_terminations}` |
| GET · POST · PUT · DELETE | `/api/ui/circuits[/{id}]` · `/circuits/types[/{id}]` | circuitos (`q`, `branch`, `supplier`, `status`) · `{supplier_id, type_id, cid, status, commit_rate_kbps, contract_id, install_date, termination_date}` |
| PUT · DELETE | `/api/ui/circuits/{id}/terminations/{A\|Z}` · `/circuits/terminations/{id}` | lado do circuito `{branch_id, location_id \| provider_network, xconnect_id, pp_info, port_speed_kbps}` |
| GET · POST · PUT · DELETE | `/api/ui/ipam/prefixes[/{id}]` · `/prefixes/{id}/next-ip` | árvore com `depth`/`utilization` (`q` aceita IP contido, `vrf=0` = global, `within`) · detalhe (pais, filhos, faixas, IPs, `slots`, `available_prefixes`) · próximo IP livre |
| GET · POST · PUT · DELETE | `/api/ui/ipam/ranges[/{id}]` · `/ranges/{id}/next-ip` | faixas `{start_address, end_address, vrf_id, status, role, mark_utilized}` |
| GET · POST · PUT · DELETE | `/api/ui/ipam/ip-addresses[/{id}]` | IPs (`q` aceita rede `10.1.0.0/16`, `assigned`, `device`) · `{address, vrf_id, port_id, status, role, dns_name, make_primary}` |
| GET · POST · PUT · DELETE | `/api/ui/ipam/vrfs` · `/vlan-groups` · `/vlans[/{id}]` | VRFs, grupos e VLANs (detalhe da VLAN com portas e prefixos) |
| GET | `/api/ui/dcim/topology?branch&mode=logical\|physical&vms=1` | grafo do mapa `{nodes: [{id, kind: device\|circuit\|cloud\|cluster\|vm, …}], links: [{source, target, kind: data\|power\|console\|member\|virtual\|provider, color, via, …}]}` |
| GET | `/api/ui/dcim/devices/{id}/path-to-internet` | `{found, start, segments: [{from_port, to_kind, to_device, to_port, cid, provider_network, cables, trace}], reason: no_cable\|no_route\|null}` |

### Virtualização (`/api/ui/virtualization` — ADR-0017)

| Método | Rota | Uso |
|---|---|---|
| GET | `/api/ui/virtualization/choices` | tipos, grupos, status de cluster/VM |
| POST · PUT · DELETE | `/lookups/{type\|group}[/{id}]` | plataformas e grupos `{name}` |
| GET · POST · PUT · DELETE | `/clusters[/{id}]` | lista com consumo × capacidade (`q`, `branch`, `type`) · detalhe (`hosts_list`, `vm_list`) · `{name, type_id, group_id, branch_id, status, version, management_url}` |
| POST | `/clusters/{id}/hosts` | `{device_id, member: true\|false}` — tirar o host solta as VMs dele |
| GET · POST · PUT · DELETE | `/vms[/{id}]` | lista (`q` aceita IP, `cluster`, `host`, `branch`, `status`) · `{name, cluster_id, device_id, vcpus, memory_mb, disk_gb, vmid, platform, asset_uuid, copy_interfaces, primary_ip_id}` |
| POST · PUT · DELETE | `/vms/{id}/interfaces` · `/interfaces/{id}` | NICs em lote (`net[0-3]`) `{name, bridge, mac_address, mode, untagged_vlan_id, tagged_vlan_ids}` → `201 {ids}` |
| POST · PUT · DELETE | `/vms/{id}/disks[/{disk}]` · `/disks/{id}` | `{name, size_gb, storage}` |

IP de VM: `POST /api/ui/ipam/ip-addresses` com `vm_interface_id` (e `make_primary`).

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
