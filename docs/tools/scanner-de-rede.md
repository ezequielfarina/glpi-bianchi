# Ferramentas — scanner de rede e calculadora de sub-rede

Menu **Ferramentas** do painel. Decisão de arquitetura: [ADR-0012](../adr/0012-ferramentas-scanner-de-rede.md).

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
