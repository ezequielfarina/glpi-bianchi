# Forçar inventário e frequência de coleta

## Pelo painel

- **Agentes → Forçar** ou **Ativo → Forçar inventário**: o servidor chama
  `http://<ip-do-agente>:62354/now`. O resultado aparece em ~10–30 s em *Inventários recebidos*.
- **Configurações → Frequência de inventário**: horas até o próximo contato (`expiration`).
  Vale a partir do próximo contato de cada agente.

## Requisitos de rede

| Origem | Destino | Porta | Onde liberar |
|---|---|---|---|
| CT do Bianchi | estações | TCP 62354 | Windows Defender Firewall da estação; política FortiGate entre VLANs |

Windows (GPO ou local):
```powershell
New-NetFirewallRule -DisplayName "GLPI Agent httpd" -Direction Inbound -Protocol TCP -LocalPort 62354 `
  -RemoteAddress 192.168.110.55 -Action Allow -Profile Domain,Private
```
FortiGate (exemplo):
```
config firewall policy
    edit 0
        set name "bianchi-to-agents-62354"
        set srcintf "<interface-servidores>"
        set dstintf "<interface-estacoes>"
        set srcaddr "h-glpi-bianchi"
        set dstaddr "n-estacoes"
        set service "tcp-62354"
        set action accept
        set schedule "always"
    next
end
```

## Confiança no agente (`httpd-trust`)

O agente aceita `/now` de `127.0.0.1` e do host configurado em `server`. Se o botão retornar
**403 / recusou**, adicione o IP do servidor no agente:

- Windows (registro): `HKLM\SOFTWARE\GLPI-Agent` → `httpd-trust` = `127.0.0.1/32,192.168.110.55/32`
  e `Restart-Service glpi-agent`.
- Linux: `/etc/glpi-agent/conf.d/bianchi.cfg` → `httpd-trust = 127.0.0.1/32,192.168.110.55/32`.

## Diagnóstico

```bash
# do CT: o agente responde?
curl -s -m 3 http://<ip-da-estacao>:62354/status      # "status: waiting"
# na estação: o serviço HTTP está escutando?
Get-NetTCPConnection -LocalPort 62354 -State Listen
```

## Na própria máquina (sem painel)

```powershell
& "C:\Program Files\GLPI-Agent\glpi-agent.bat" --force
# ou abrir no navegador da estação: http://localhost:62354/now
```
