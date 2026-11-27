# Ferramentas — scanner, monitoramento, conflitos, diagnóstico e calculadora

Menu **Ferramentas** do painel. Decisões: [ADR-0012](../adr/0012-ferramentas-scanner-de-rede.md) (scanner) e [ADR-0013](../adr/0013-monitoramento-alertas-diagnostico.md) (monitoramento, conflitos, diagnóstico).

## Scanner de rede

```
Painel ──POST /api/ui/tools/scanner/scans──► ScanService (valida alvo/perfil, grava network_scan=queued)
                                              └─ Messenger "scan" (Valkey db/scan) ──► bianchi-scan-worker
                                                   └─ RunNetworkScanHandler
                                                        ├─ nmap -oX - (streaming → network_scan_host)
                                                        ├─ SNMP ARP do gateway (opcional)
                                                        ├─ DeviceClassifier + MacVendors
                                                        └─ ScanCorrelator (MAC → IP → nome) + filial
Painel ◄──GET /scans/{id} a cada 2 s── progresso, fase, hosts parciais
```

### Pré-requisitos no CT

O `install.sh` instala `nmap`, `php8.x-snmp` e `iproute2` e habilita o `bianchi-scan-worker`.

```bash
systemctl status bianchi-scan-worker
journalctl -u bianchi-scan-worker -f
# capabilities efetivas do worker (bit 13 = CAP_NET_RAW)
grep CapEff /proc/$(systemctl show -p MainPID --value bianchi-scan-worker)/status
capsh --decode=$(awk '/CapEff/{print $2}' /proc/$(systemctl show -p MainPID --value bianchi-scan-worker)/status)
```

**LXC não privilegiado:** o CT precisa ter `cap_net_raw` no bounding set. Esse é o padrão do Proxmox;
ele só falta se houver `lxc.cap.drop` customizado. Confira no host:

```bash
grep -E 'cap.drop|cap.keep' /etc/pve/lxc/500.conf
pct exec 500 -- capsh --print | grep -i net_raw
```

Se a capability não estiver disponível, a varredura roda em modo **não privilegiado** (`-sT`, sem ARP/SO)
e a tela mostra o aviso. Para corrigir, remova `net_raw` do `lxc.cap.drop` e reinicie o CT.

### Perfis

| Perfil | Limite | Tempo típico /24 | Detalhes |
|---|---|---|---|
| Descoberta rápida | /20 | segundos | ping/ARP, DNS reverso, NetBIOS |
| Padrão | /22 | 1–5 min | top 200 portas, versões, SO, HTTP title, certificado TLS, SMB/RDP NTLM, SNMP sysDescr |
| Completo | /24 | 10–40 min | TCP 1-1024 + infra (8291, 8728, 9100, 5060…), UDP 53/67/69/123/137/161/500/623/1900/5353 |

Regras:

- alvo só em faixa privada/CGNAT;
- no máximo 5 varreduras pendentes;
- uma varredura em andamento pode ser cancelada (o worker envia SIGTERM ao nmap).

### MAC de outras VLANs (SNMP)

ARP só funciona no segmento L2 do CT. Para VLANs roteadas, marque **"Obter o MAC de dispositivos de outras
VLANs"** e informe o gateway (FortiGate/MikroTik/switch L3) e a community v2c **somente leitura**.
A community não é gravada no banco e trafega cifrada até o worker.

FortiGate: habilite SNMP só para o IP do CT, na interface que ele alcança.

```
config system snmp sysinfo
    set status enable
end
config system snmp community
    edit 10
        set name "<community-ro>"
        config hosts
            edit 1
                set ip 192.168.110.55 255.255.255.255
            next
        end
        set query-v2c-status enable
        set trap-v2c-status disable
        set query-v1-status disable
    next
end
config system interface
    edit "<interface>"
        append allowaccess snmp
    next
end
```

MikroTik:

```
/snmp community add name=<community-ro> addresses=192.168.110.55/32 read-access=yes write-access=no
/snmp set enabled=yes
```

Teste a partir do CT antes de usar na tela:

```bash
snmpwalk -v2c -c '<community-ro>' -t 2 -r 1 <gateway> 1.3.6.1.2.1.4.22.1.2 | head
snmpwalk -v2c -c '<community-ro>' <gateway> 1.3.6.1.2.1.4.35.1.4 | head   # IP-MIB novo
```

### FortiGate / IPS

Varreduras de portas disparam assinaturas de *port scan* e anomalias DoS (`tcp_port_scan`, `udp_scan`)
quando atravessam políticas com IPS ou DoS policy. Para não bloquear o CT:

- crie uma exceção de IPS/DoS para o IP de origem 192.168.110.55, ou use uma política dedicada sem sensor;
- ou use o perfil **Descoberta rápida** em segmentos remotos.

Diagnóstico de bloqueio:

```
diagnose debug flow filter saddr 192.168.110.55
diagnose debug flow trace start 50
diagnose debug enable
# ...rodar a varredura...
diagnose debug disable
diagnose ips anomaly list | grep 192.168.110.55
```

### Troubleshooting

| Sintoma | Verificação |
|---|---|
| Fica em "na fila" | `systemctl status bianchi-scan-worker`; `redis-cli -n 0 KEYS '*scan*'`; `MESSENGER_SCAN_DSN` no `.env.local` |
| "modo não privilegiado" | capabilities (acima); `systemctl cat bianchi-scan-worker \| grep Ambient` |
| Sem MAC | host em outra VLAN → usar SNMP; ou host bloqueia ICMP e ARP falhou fora do L2 |
| SNMP com aviso de timeout | ACL/`allowaccess snmp`, community, UDP 161 na política entre VLANs |
| Hosts "down" que existem | firewall do host descarta ICMP/TCP 80/443 → perfil Padrão/Completo usa mais sondas |
| Falha imediata | `journalctl -u bianchi-scan-worker -n 50`; rode manualmente: `sudo -u www-data nmap --privileged -sn 192.168.110.0/28 -oX -` |

Correlação com o inventário, nesta ordem:

1. MAC de `network_port.mac` ou de `attributes->>'mac'`;
2. IP de `ip_address` ou de `attributes->>'ip'`;
3. nome (NetBIOS ou primeiro rótulo DNS).

### API

| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/ui/tools/scanner` | versão do nmap, SNMP disponível, perfis, sugestões de rede |
| GET/POST | `/api/ui/tools/scanner/scans` | histórico / nova varredura (`target`, `profile`, `snmp_gateway`, `snmp_community`) → 202 |
| GET/DELETE | `/api/ui/tools/scanner/scans/{id}` | detalhe com hosts / excluir |
| POST | `/api/ui/tools/scanner/scans/{id}/cancel` | cancelar |
| GET | `/api/ui/tools/scanner/scans/{id}/export` | CSV (`;`, UTF-8 com BOM, abre direto no Excel) |

## Calculadora de sub-rede

Roda inteiramente no navegador (`frontend/src/lib/subnet.ts`) e está disponível para todos os perfis.

- **Entrada:** `IP/prefixo`, `IP máscara` (pontuada) ou só o IP (usa a máscara classful).
- **Saída:**
  - rede, broadcast, faixa utilizável, hosts, máscara e wildcard;
  - classe e tipo do endereço com a RFC (1918, 6598, 3927, 5737...);
  - decimal, hexadecimal e binário com os bits de rede/host destacados;
  - zona de DNS reverso.
- **Divisão em sub-redes:** até /+12, listando no máximo 1024 linhas; destaca a sub-rede do IP informado.
- **Atalhos:** redes vizinhas e "preciso de N hosts" → prefixo.
- **Configuração pronta:** FortiOS, RouterOS, iproute2, PowerShell e Cisco IOS/ACL. O gateway assumido é o primeiro host.
- /31 segue a RFC 3021 (2 hosts ponto a ponto) e /32 é host único.
- A URL guarda a entrada (`/app/tools/subnet?ip=10.0.0.0/22`), então dá para compartilhar o cálculo.

## Monitoramento: varreduras agendadas e alertas (ADR-0013)

**Ferramentas → Monitoramento.** Cada agendamento roda uma varredura e a compara com a execução anterior.
A 1ª execução é a linha de base.

| Alerta | Severidade | Quando |
|---|---|---|
| Conflito de IP | crítico | arp-scan viu 2+ MACs respondendo pelo mesmo IP (qualquer varredura) |
| MAC mudou | atenção / **crítico no gateway** | mesmo IP, MAC diferente (troca de equipamento, clonagem, ARP spoofing) |
| Dispositivo novo | atenção | MAC que não estava na anterior e não existe no inventário |
| Portas novas | info / atenção se for porta de risco | mesmo dispositivo e mesmo perfil, com portas novas (exige Padrão/Completo) |
| Ativo sumiu | info | ativo do inventário que respondia e não respondeu (desligado por padrão) |
| Varredura falhou | atenção | erro na execução agendada |

Alerta repetido enquanto aberto só incrementa "visto N×" e **não notifica de novo**.

```bash
systemctl list-timers bianchi-scan-scheduler.timer
journalctl -u bianchi-scan-scheduler -n 20          # "Lab: varredura #12" / "pulado: execução anterior ainda em andamento"
sudo -u www-data php bin/console app:scan:tick -v   # tick manual
```

### Notificações (só Administrador)

| Canal | Como obter o destino |
|---|---|
| Microsoft Teams | no canal: ⋯ → **Workflows** → "Postar em um canal quando uma solicitação de webhook for recebida" → copie a URL. Os conectores O365 antigos foram descontinuados |
| Telegram | `@BotFather` → `/newbot` (token). Adicione o bot ao grupo e pegue o chat id em `https://api.telegram.org/bot<token>/getUpdates` (grupo = `-100…`) |
| Slack / Mattermost / Google Chat | Incoming Webhook (formato `{"text": …}`) |
| Webhook JSON | qualquer endpoint (n8n, Node-RED, Zabbix via script): `{"source":"glpi-bianchi","event":"network_alerts","title",…,"alerts":[{"kind","severity","title","ip","mac","detail"}]}` |

O CT precisa de saída HTTPS para `api.telegram.org`, `*.logic.azure.com` etc. **Com deep inspection no FortiGate:**
importe a CA de inspeção no CT, ou crie uma isenção SSL para esses FQDNs.

```bash
# CA do FortiGate (System → Certificates → Fortinet_CA_SSL → Download)
cp Fortinet_CA_SSL.cer /usr/local/share/ca-certificates/fortinet-ca.crt && update-ca-certificates
curl -sS -o /dev/null -w '%{http_code}\n' https://api.telegram.org   # 302/200 = ok; erro de certificado = CA faltando
```

```
config firewall wildcard-fqdn custom
    edit "g-telegram"
        set wildcard-fqdn "*.telegram.org"
    next
    edit "g-logic-azure"
        set wildcard-fqdn "*.logic.azure.com"
    next
end
config firewall ssl-ssh-profile
    edit "deep-inspection-bianchi"
        config ssl-exempt
            edit 0
                set type wildcard-fqdn
                set wildcard-fqdn "g-telegram"
            next
            edit 0
                set type wildcard-fqdn
                set wildcard-fqdn "g-logic-azure"
            next
        end
    next
end
```

## Conflitos de IP/MAC

**Ferramentas → Conflitos de IP/MAC**, da evidência mais forte para a mais fraca:

1. **IP em conflito (ARP)**: o arp-scan roda automaticamente nas varreduras de redes **ligadas a uma interface
   do CT**, com o worker privilegiado. Conflito real, VRRP/HSRP/CARP ou proxy-ARP (confira se o IP é virtual).
2. **IP em uso por outro equipamento**: a varredura viu o IP com o MAC A, mas um ativo inventariado há ≤ 2 dias
   diz que o IP é dele (MAC B). Causa típica: IP fixo dentro do escopo do DHCP.
3. **MAC repetido em vários ativos**: VM clonada sem regenerar o MAC, cadastro duplicado (manual + agente) ou
   dock/adaptador USB que passou de um notebook para outro.
4. **IP repetido no inventário**: inventários com menos de 6 h de diferença indicam conflito provável; inventários
   distantes no tempo indicam reaproveitamento pelo DHCP.

Ignorados: portas marcadas como virtuais pelo agente e MACs fixos de adaptadores (FortiClient `00:09:0f:fe:00:01`,
AnyConnect, Npcap, VMware VMnet1/8, VirtualBox host-only).

Para pegar conflito **em outra VLAN** com o arp-scan, o CT precisa de uma interface naquela VLAN (por exemplo, uma
interface tagueada extra no CT 500, sem gateway). Exemplo no Proxmox:

```bash
pct set 500 -net1 name=eth1,bridge=vmbr0,tag=20,ip=192.168.20.250/24
```

```bash
# validar manualmente dentro do CT
arp-scan -I eth0 -x -q -r 2 192.168.110.0/24 | sort | awk '{print $1}' | uniq -d   # IPs com 2+ respostas
```

## Diagnóstico e consultas

**Ferramentas → Diagnóstico e consultas.** Tudo é executado **a partir do CT**, ou seja, mostra o que o servidor
enxerga (útil para validar política entre VLANs no FortiGate). Também há o atalho **Diagnosticar** no ativo e nos alertas.

| Aba | Execução | Observações |
|---|---|---|
| Ping | `ping -O -c N -W 2` | TTL estimado (64 Linux / 128 Windows / 255 rede) |
| Traceroute / MTR | `mtr --json -b -c N` (fallback `traceroute` UDP) | perda só nos saltos intermediários = rate-limit de ICMP, não perda real |
| Teste de portas | conexão TCP do PHP, até 32 portas | aberta / fechada (RST) / filtrada (DROP); TLS: validade, SAN, cadeia confiável para o CT; banner (SSH, SMTP, FTP) |
| DNS | `dig`, até 5 servidores lado a lado | "respostas diferentes" = split-horizon, propagação ou cache |
| Whois / ASN | RDAP (HTTPS) + Team Cymru via DNS TXT | IP privado/CGNAT é explicado sem consulta externa |
| Fabricante do MAC | base do nmap (MA-L/M/S) | sinaliza VM (Proxmox `bc:24:11`, VMware, Hyper-V…), MAC aleatório e adaptadores virtuais; mostra onde o MAC aparece |

Se algo falhar no CT:

```bash
cat /proc/self/gid_map                   # CT unprivileged: "0 100000 65536" → faixa máxima 0..65535
sysctl net.ipv4.ping_group_range        # deve ser "0 65535" (unprivileged) ou "0 2147483647" (privileged); "1 0" = bloqueado
sysctl -w net.ipv4.ping_group_range="0 65535"   # correção imediata; install.sh persiste em /etc/sysctl.d/60-bianchi-ping.conf
sudo -u www-data ping -c1 192.168.110.1
getcap /usr/bin/mtr-packet              # cap_net_raw=ep
sudo -u www-data mtr -r -c1 8.8.8.8
dig +short 8.8.8.8.origin.asn.cymru.com TXT   # ASN depende de DNS externo
curl -sS https://rdap.org/domain/registro.br -o /dev/null -w '%{http_code}\n'   # RDAP (302/200)
```
