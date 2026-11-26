# ADR-0012: Menu Ferramentas — scanner de rede (nmap em worker dedicado) e calculadora de sub-rede

- **Status:** aceito
- **Data:** 2026-11-26
- **Decisores:** Ezequiel Farina

## Contexto

O inventário cobre bem o que tem GLPI Agent. Ficam de fora impressoras, câmeras, APs, switches,
telefones IP e qualquer coisa "esquecida" na rede. O dono do produto pediu um **scanner de rede**:
informar uma sub-rede, varrer e listar IP, MAC, fabricante e tipo de dispositivo com o máximo de
detalhe, cruzando com o inventário. Pediu também uma **calculadora de sub-rede**.

Restrições:

- o CT é **LXC não privilegiado**, sem Docker;
- o PHP-FPM não pode ganhar privilégios de rede;
- uma varredura `/24` completa leva minutos e não cabe numa requisição HTTP;
- a rede tem várias VLANs roteadas pelo FortiGate, então ARP local só enxerga o próprio segmento.

## Decisão

1. **Motor: nmap**, executado com `proc_open` usando um vetor de argumentos, sem shell.
   - A saída é XML em streaming (`-oX -`).
   - `<taskbegin>`/`<taskprogress>` alimentam a fase e o percentual.
   - Cada `<host>` é gravado assim que fecha, então os resultados aparecem durante a varredura.
   - Avaliamos reescrever a varredura em PHP (ICMP/ARP/TCP connect) e descartamos: fingerprint de
     SO, detecção de versão e scripts NSE não seriam reproduzíveis com qualidade.
2. **Execução assíncrona em worker dedicado** `bianchi-scan-worker.service`:
   - transporte Messenger `scan`, separado do inventário;
   - `max_retries: 0`, e o handler nunca lança exceção (uma falha vira `status=failed`);
   - recebe `AmbientCapabilities=CAP_NET_RAW CAP_NET_ADMIN`, sem root, e roda o nmap com
     `--privileged` (ARP, SYN scan, `-O`);
   - sem as capabilities, cai para `--unprivileged -sT`, sem ARP e sem SO, e registra o aviso na varredura.
3. **MAC de outras VLANs:** opcionalmente, o worker lê a tabela ARP do gateway via **SNMP v2c**
   (`ipNetToMediaPhysAddress` e depois `ipNetToPhysicalPhysAddress`).
   - A community trafega **cifrada (SecretBox)** na mensagem e **não é persistida**.
   - Também usa o NetBIOS (`nbstat`) para hosts Windows.
   - O fabricante vem do `nmap-mac-prefixes`; MAC aleatório é detectado pelo bit localmente administrado.
4. **Perfis** com limite de tamanho, para não virar um DoS da própria rede:

   | Perfil | Limite | O que faz |
   |---|---|---|
   | `discovery` | até /20 | ping/ARP + nbstat |
   | `standard` | até /22 | top 200 portas, versões leves, SO, scripts de identificação |
   | `full` | até /24 | TCP 1-1024 + infraestrutura, UDP selecionadas, versões, `--osscan-guess` |

   - Só aceita alvos em faixas **privadas/CGNAT** (10/8, 172.16/12, 192.168/16, 100.64/10).
   - No máximo 5 varreduras pendentes.
5. **Classificação e correlação:**
   - `DeviceClassifier` combina o `devicetype` do nmap, o fabricante e as portas para chegar ao tipo de ativo.
   - `ScanCorrelator` liga o host a um ativo por MAC, depois IP, depois nome.
   - A filial é a que contém (ou está contida no) alvo, e o prefixo mais específico vence.
   - "Cadastrar" abre o formulário de ativo já preenchido. Nada é criado automaticamente.
6. **Persistência:** tabelas `network_scan` e `network_scan_host`.
   - Portas, serviços, certificados e scripts ficam em JSONB.
   - A tupla `(scan_id, ip)` é única, para o upsert do streaming e da tabela ARP.
7. **Acesso:** `^/api/ui/tools` exige `ROLE_EDITOR` (Técnico ou Administrador).
   - Cada varredura registra quem pediu.
   - A **calculadora de sub-rede** é 100% no navegador (`lib/subnet.ts`), sem API, e fica disponível
     para todos os perfis.

## Consequências

- (+) Ativos sem agente passam a ser descobertos e cadastrados em dois cliques.
- (+) O PHP-FPM continua sem privilégio; só um processo isolado tem `CAP_NET_RAW`.
- (−) No LXC não privilegiado, a capability precisa existir no bounding set do CT. Sem ela, o modo
  degradado não traz MAC/SO (ver [docs/tools/scanner-de-rede.md](../tools/scanner-de-rede.md)).
- (−) A varredura gera tráfego e pode disparar IPS/IDS (FortiGate). É preciso documentar exceção
  ou agendar fora do horário.
- (−) Dependências novas no CT: `nmap`, `php-snmp`, `iproute2`.
