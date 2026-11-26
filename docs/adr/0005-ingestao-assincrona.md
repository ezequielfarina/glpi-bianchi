# ADR-0005: Ingestão assíncrona com payload bruto persistido

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

No GLPI o inventário é processado de forma síncrona dentro do request do agente. Um switch
grande pode levar minutos e consumir cerca de 500 MB (comentário em `AbstractRequest::handleRequest`). Em
rajada (todos os agentes às 08:00) isso esgota os workers do PHP-FPM.

## Decisão

1. **Request (síncrono, ms):** decodifica → valida contra o JSON Schema oficial → grava
   `raw_inventory` (payload em JSONB) → publica `ProcessInventory(id)` → responde `ok`.
2. **Worker (assíncrono):** `bianchi-worker@N` consome a fila e processa numa única transação,
   com `pg_advisory_xact_lock(hashtext(deviceid))` para serializar o mesmo dispositivo.
3. A mensagem carrega **só o id**; o payload fica no PostgreSQL (fila leve).
4. Falha → status `failed` + retry (3x, backoff exponencial) → fila `failed` (inspecionável).
5. Handler idempotente: reentrega de mensagem já processada é ignorada.

## Consequências

- O agente nunca espera o processamento, e o FPM aguenta rajadas com poucos processos.
- O payload bruto permite **reprocessar** após corrigir bug no importador e diagnosticar
  "o que o agente mandou" sem acesso à máquina.
- Erros de importação não voltam para o agente (ele sempre recebe `ok` após a validação).
  O monitoramento precisa olhar `raw_inventory.status = 'failed'`.
- Retenção: o payload cresce. Fase 2: job que mantém os N últimos por device.
