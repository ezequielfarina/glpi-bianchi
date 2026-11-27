# ADR-0013: Monitoramento de rede (varreduras agendadas e alertas), conflitos de IP/MAC e diagnóstico

- **Status:** aceito
- **Data:** 2026-11-26
- **Decisores:** Ezequiel Farina

## Contexto

Com o scanner ([ADR-0012](0012-ferramentas-scanner-de-rede.md)) a descoberta acontece sob demanda.
O dono do produto aprovou quatro evoluções:

1. **varredura agendada** com alerta quando a rede muda;
2. **detector de conflito de IP e de MAC clonado**;
3. **ping, traceroute/MTR e teste de porta** executados a partir do servidor;
4. **consultas**: DNS, whois/ASN e fabricante do MAC.

Restrições:

- sem Docker e sem novas dependências PHP (o `composer.lock` é versionado e o CT instala em modo produção);
- o FortiGate faz inspeção SSL, o que afeta HTTPS para fora;
- o PHP-FPM continua sem privilégios.

## Decisão

### Agendamento

- Tabela `scan_schedule`, com frequência `interval` (a cada N horas) ou `daily` (HH:MM em dias ISO), no fuso `America/Sao_Paulo`.
- Um **timer systemd** (`bianchi-scan-scheduler.timer`, a cada minuto) roda `app:scan:tick`. Descartamos o
  `symfony/scheduler`: seria dependência nova e exigiria outro worker permanente.
- O tick tem advisory lock e "reserva" a janela com `UPDATE … WHERE next_run_at = <lido>`, então dois ticks
  simultâneos não disparam duas vezes.
- Não há sobreposição: se a execução anterior ainda roda, a janela é pulada.
- A varredura reutiliza o fluxo do ADR-0012 (fila `scan` + `bianchi-scan-worker`) e ganha `network_scan.schedule_id`.
- **A comunidade SNMP do agendamento é persistida cifrada** (SecretBox/`DATA_ENCRYPTION_KEY`). É uma exceção
  consciente ao ADR-0012, porque a execução acontece sem o usuário presente. Ela nunca volta para a API;
  a tela recebe só `has_community`.

### Alertas

- O `ChangeDetector` roda ao fim de cada varredura e compara com a **execução anterior do mesmo agendamento**.
  A primeira execução vira a "linha de base".

  | Alerta | Quando dispara |
  |---|---|
  | `new_device` | MAC ausente na anterior e sem ativo no inventário |
  | `mac_changed` | o IP mudou de MAC e o novo MAC não é aleatório; no gateway provável (`.1`/`.254`/gateway SNMP) é **crítico** |
  | `new_ports` | mesmo dispositivo e mesmo perfil, com portas novas; vira "atenção" se a porta for de risco (RDP, SMB, Telnet, bancos…) |
  | `device_gone` | ativo do inventário que respondia e sumiu |
  | `scan_failed` | a varredura agendada falhou |
  | `ip_conflict` | ver "Conflitos" abaixo |

- Um MAC trocado num IP conhecido gera só `mac_changed`, não também `new_device`.
- Tabela `network_alert`, com **deduplicação** por `fingerprint` = SHA-256(tipo + chave).
  - Enquanto um alerta estiver aberto, a repetição só soma `occurrences`.
  - Depois de reconhecido, a mesma condição abre um alerta novo.
  - Reconhecidos com mais de 180 dias são removidos pelo tick.
- Optamos por **não usar índice único parcial** (`WHERE acknowledged_at IS NULL`): o comparador do DBAL
  compara o predicado como texto, e o Postgres o devolve com parênteses, o que quebra o `schema:validate`.
  Só o worker grava alertas (um consumidor), então a deduplicação em código basta.

### Notificações

- Tabela `notification_channel`, gerenciada só pelo Administrador.
- Tipos de canal:
  - Microsoft Teams (Workflows/Adaptive Card);
  - Telegram (Bot API, HTML);
  - Slack/Mattermost/Google Chat (`{"text"}`);
  - webhook JSON genérico.
- URL e token ficam **cifrados**; a API devolve apenas uma máscara e mensagens de erro nunca incluem a URL.
- Um envio por varredura e por canal, só com alertas **novos**, filtrados pela severidade mínima do canal.
- O e-mail ficou de fora: exigiria `symfony/mailer` e configuração de SMTP. Fica como próximo passo.

### Conflitos de IP/MAC

- **Conflito real:** o worker roda o **arp-scan** antes do nmap quando o alvo está num segmento ligado a uma
  interface do CT e há `CAP_NET_RAW`.
  - O nmap guarda uma resposta por IP; o arp-scan registra todas. Dois MACs no mesmo IP vão para
    `details.arp_conflict` e geram um alerta `ip_conflict` crítico, em qualquer varredura.
  - De quebra, o arp-scan completa o MAC de hosts que o nmap não viu.
- **Relatório "Conflitos"**, calculado por consulta sem tabela nova:
  1. conflitos ARP;
  2. IP em uso por um MAC diferente do que o inventário recente (≤ 2 dias) atribui ao IP, ignorando troca de DHCP;
  3. o mesmo MAC físico em 2+ ativos, com dica de causa (VM clonada, cadastro duplicado, adaptador USB/dock);
  4. o mesmo IP em 2+ ativos inventariados no período dentro das redes das filiais.
- Portas virtuais e MACs fixos de adaptadores virtuais são ignorados: FortiClient `00:09:0f:fe:00:01`,
  Npcap, AnyConnect, VMnet1/8 e VirtualBox host-only.
- O `MacVendors` passa a usar o **prefixo mais longo** (MA-S 36 bits → MA-M 28 bits → MA-L 24 bits), que já
  vem na base do nmap.

### Diagnóstico e consultas

- Executados **de forma síncrona no PHP-FPM**, com limites curtos e argv sem shell. A validação de alvo
  rejeita qualquer coisa que pareça opção (`-…`).

  | Ferramenta | Como funciona |
  |---|---|
  | Ping | `ping -O`; o install.sh libera `net.ipv4.ping_group_range` dentro do CT |
  | Traceroute | `mtr --json` (mtr-packet com `cap_net_raw` do pacote), com fallback para `traceroute` UDP |
  | Teste de portas | `stream_socket_client`: aberta, fechada (RST) ou filtrada (timeout); certificado TLS (validade, SAN, cadeia confiável para o CT) e banner |
  | DNS | `dig`, comparando até 5 servidores lado a lado e sinalizando respostas divergentes (split-horizon) |
  | ASN | Team Cymru **via DNS TXT**: não depende da porta 43, que costuma estar bloqueada |
  | Whois | **RDAP** via HTTPS (rdap.org → RIR/registro.br), estruturado; substitui o whois de texto livre |
  | Fabricante do MAC | em lote, com flags (local, multicast, VM/hipervisor, adaptador virtual) e onde o MAC aparece no inventário e nas varreduras |

- Acesso: Técnico/Administrador (`^/api/ui/tools`), e cada uso de ping, trace ou portas é registrado em log.

## Consequências

- (+) A rede passa a ser vigiada continuamente; mudança e conflito chegam no Teams/Telegram.
- (+) O diagnóstico do ponto de vista do servidor ajuda a validar política do FortiGate entre VLANs.
- (−) Novos pacotes no CT: `arp-scan`, `bind9-dnsutils`, `mtr-tiny`, `traceroute`, `iputils-ping`.
- (−) Uma unit nova: o timer `bianchi-scan-scheduler`.
- (−) O arp-scan só detecta conflito no segmento L2 do CT. Para outras VLANs, o conflito aparece indiretamente
  (troca de MAC no IP / scan × inventário). Uma interface do CT por VLAN crítica, ou um coletor por filial,
  ficam como evolução.
- (−) RDAP, notificações externas e ASN dependem de saída para a internet; com inspeção SSL, o CT precisa
  confiar na CA do FortiGate.
