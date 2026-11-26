# Protocolo do GLPI Agent

Implementação: `src/Inventory/Controller/AgentController.php` + `src/Inventory/Protocol/`.
Referência no GLPI: `src/Glpi/Agent/Communication/AbstractRequest.php` e `src/Glpi/Inventory/Request.php`.

## Transporte

| Item | Valor |
|---|---|
| Método | `POST` (`GET` responde 200 text/plain, como health-check) |
| Rotas | `/` · `/front/inventory.php` · `/inventory` · `/Inventory` |
| Autenticação | nenhuma no MVP (igual ao padrão do GLPI). Restrinja por firewall |
| Tamanho | até `INVENTORY_MAX_PAYLOAD_BYTES` descompactado (padrão 64 MiB); nginx `client_max_body_size 64m` |

### Compressão (por `Content-Type`)

| Content-Type | Compressão | Resposta |
|---|---|---|
| `application/x-compress-zlib`, `application/x-zlib` | zlib (**padrão do agente**) | mesma |
| `application/x-compress-gzip`, `application/x-gzip` | gzip | mesma |
| `application/x-compress-deflate`, `application/x-deflate` | deflate | mesma |
| `application/json` / `application/xml` / outro | nenhuma | `application/json` ou `application/xml` |

O modo (JSON ou XML) é detectado pelo 1º caractere do corpo descompactado (`<` = XML).
O header `GLPI-Agent-ID` identifica agente moderno, que recebe resposta JSON mesmo a PROLOG.

## Ações

### `contact` (JSON)

```json
→ {"action":"contact","deviceid":"pc-001-2026-11-26-10-00-00","enabled-tasks":["inventory","netdiscovery"]}
← {"expiration":24,"status":"ok",
   "tasks":{"inventory":{"server":"glpi-bianchi","version":"0.1.0"}},
   "message":"netdiscovery task(s) not supported","disabled":"netdiscovery"}
```
Regra herdada do GLPI: se `esx`, `netdiscovery`, `netinventory` ou `remoteinventory` estiverem
habilitadas, `inventory` é considerada habilitada. Tarefas não suportadas voltam em `disabled`,
e o agente as desliga localmente.

### `prolog` (XML legado)

```xml
→ <REQUEST><DEVICEID>pc-001-...</DEVICEID><QUERY>PROLOG</QUERY></REQUEST>
← <REPLY><PROLOG_FREQ>24</PROLOG_FREQ><RESPONSE>SEND</RESPONSE></REPLY>
```

### `inventory` (JSON ou XML)

```json
→ {"action":"inventory","deviceid":"...","itemtype":"Computer","partial":false,"content":{...}}
← {"expiration":24,"status":"ok"}
```
1. Validação contra `inventory.schema.json` (`glpi-project/inventory_format`). Falha → **400**
   `{"status":"error","message":"..."}`.
2. XML é convertido para JSON pelo `Glpi\Inventory\Converter`.
3. Gravado em `raw_inventory` e enfileirado; a resposta sai **antes** do processamento.
4. `itemtype` ≠ `Computer` no MVP: aceito (200) e gravado como `rejected` (sem processamento).

### `get_params`

Responde `{"expiration":24,"status":"ok"}`. Reservado para parâmetros remotos (fase 2).

## Expiração

`expiration` (horas) = `INVENTORY_FREQUENCY_HOURS` (padrão 24). O agente agenda o próximo
contato com base nesse valor (com jitter próprio).

## Erros

| HTTP | Quando |
|---|---|
| 400 | corpo vazio, JSON/XML malformado, falha de descompressão, schema inválido, action desconhecida, sem `deviceid` |
| 500 | erro interno (o agente tenta de novo no próximo ciclo) |

## Teste manual

```bash
tools/send-inventory.sh tests/Fixtures/inventory/computer-linux.json http://127.0.0.1/

# Lado do agente (Linux), forçando envio com log detalhado
glpi-agent --server http://<ct>/ --force --debug --logger=stderr
# Gerar inventário local sem enviar (útil para novas fixtures)
glpi-inventory --json > /tmp/inv.json
```
