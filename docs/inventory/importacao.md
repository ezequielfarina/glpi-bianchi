# Importação: do payload ao ativo

Código: `src/Inventory/MessageHandler/ProcessInventoryHandler.php` → `src/Inventory/Import/`.

## Sequência

```mermaid
sequenceDiagram
    participant W as bianchi-worker
    participant H as ProcessInventoryHandler
    participant DB as PostgreSQL
    participant I as ComputerImporter
    W->>H: ProcessInventory(raw_id)
    H->>DB: BEGIN; pg_advisory_xact_lock(hashtext(deviceid))
    H->>I: import(raw)
    I->>DB: AssetMatcher (uuid → serial → agente → nome)
    I->>I: seções por prioridade (Hardware 100, OS 90, CPU/RAM/Storage 50, Software 40, Rede 30)
    I->>DB: travas (locked_field) + histórico (asset_history)
    H->>DB: raw_inventory.status = processed; COMMIT
    Note over H,DB: erro → ROLLBACK, status = failed, retry 3x → fila "failed"
```

## Matching (`AssetMatcher`)

| Ordem | Critério | Observação |
|---|---|---|
| 1 | `hardware.uuid` | UUID SMBIOS: sobrevive a renomear e a reinstalar o SO |
| 2 | `bios.ssn` (ou `msn`) + tipo | seriais placeholder são descartados (`Value::JUNK`) |
| 3 | ativo vinculado ao agente | mesmo `deviceid`; cobre VMs sem UUID estável |
| 4 | `hardware.name` + tipo | último recurso |

Sem correspondência → ativo novo na organização do agente ou em `INVENTORY_DEFAULT_ORGANIZATION`.

## Seções

| Classe | Seções do payload | Destino |
|---|---|---|
| `HardwareSection` | `hardware`, `bios` | nome, UUID, serial, fabricante, modelo, `attributes.*` |
| `OperatingSystemSection` | `operatingsystem` | `asset.os_*` |
| `ProcessorSection` | `cpus` | `component` kind=processor |
| `MemorySection` | `memories` | `component` kind=memory (slots vazios ignorados) |
| `StorageSection` | `storages` | `component` kind=storage / drive (ópticos) |
| `SoftwareSection` | `softwares` | `software` / `software_version` / `software_installation` |
| `NetworkSection` | `networks` | `network_port` + `ip_address` (loopback ignorado; v4+v6 agrupados) |

**Para adicionar uma seção** (ex.: `videos` → GPU): crie uma classe em
`src/Inventory/Import/Section/` implementando `SectionImporter`. O autoconfigure registra a classe sozinho.
Para componentes, use `ComponentSynchronizer::sync()`. Adicione uma fixture e um teste.

## Regras de sincronização

- **Itens dinâmicos** (`dynamic = true`, vieram do agente) são removidos quando somem do
  inventário. Itens **manuais** nunca são removidos pelo inventário.
- **Inventário parcial** (`partial: true`): seção ausente = ignorada. Seção presente e vazia = remove os dinâmicos.
- **Inventário completo:** seção ausente = vazia (remove os dinâmicos).
- **Campos travados:** `locked_field (asset_id, field)` impede a sobrescrita; o valor recusado fica em
  `last_rejected_value` para diagnóstico. `asset_id NULL` = trava global.
- **Histórico:** cada campo alterado e cada componente, software ou porta adicionado/removido gera
  uma linha em `asset_history` (`source = inventory`, `actor = agent:<deviceid>`). Ativos recém-criados não geram histórico.

## Diagnóstico

```bash
# Falhas recentes
psql bianchi -c "SELECT id, device_id, error, received_at FROM raw_inventory WHERE status='failed' ORDER BY id DESC LIMIT 10"

# Reprocessar um inventário (após correção)
php bin/console messenger:failed:show
php bin/console messenger:failed:retry <id>

# Logs estruturados do canal inventory
journalctl -u 'bianchi-worker@*' -o cat | grep '"channel":"inventory"' | jq .
```
