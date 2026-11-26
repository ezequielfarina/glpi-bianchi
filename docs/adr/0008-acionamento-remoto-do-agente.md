# ADR-0008: Acionamento remoto do agente e frequência configurável no painel

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

Duas necessidades operacionais:
1. **Forçar inventário** de uma máquina específica sem acesso a ela (pós-troca de peça, auditoria).
2. **Ajustar a frequência** de coleta sem editar `.env` nem reiniciar serviços.

O GLPI Agent tem servidor HTTP embutido (porta **62354**) com os endpoints `/now` (executa as
tarefas imediatamente) e `/status`. O GLPI usa exatamente esse mecanismo
(`src/Agent.php::requestAgent`, tentando IP de origem e IPs do ativo).

## Decisão

- `POST /api/ui/agents/{deviceId}/wakeup` → `GET http://<ip>:<porta>/now`, tentando até 4
  endereços: IP de origem do último contato + IPv4 das interfaces físicas do ativo (timeout 3 s cada).
  Respostas: `ok` | `forbidden` (403: servidor fora do `httpd-trust` do agente) | `unreachable` | `no_address`.
- Configurações em tabela `app_setting` (chave → JSONB), editáveis em `/app/settings`;
  padrão vem do `.env` (`INVENTORY_FREQUENCY_HOURS`). O endpoint do agente lê o valor a cada request.
- `raw_inventory.outcome` (`created`/`updated`/`unchanged`) + `changes` gravados no processamento.

## Consequências

- **Nova direção de tráfego:** servidor → estação, TCP 62354. Exige regra no Windows Defender
  Firewall (o MSI do agente pode criar) e no FortiGate entre VLANs. Sem isso, o botão informa
  "inalcançável" — a coleta agendada não é afetada.
- Agentes atrás de NAT/VPN podem ter `remote_ip` não roteável a partir do servidor.
- Nova frequência só é aplicada quando cada agente fizer o próximo contato.
