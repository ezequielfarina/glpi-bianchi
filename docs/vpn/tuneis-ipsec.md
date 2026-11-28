# Túneis VPN IPsec (FortiGate)

Documentação dos túneis a partir da própria configuração dos FortiGates — decisão em [ADR-0022](../adr/0022-tuneis-vpn-ipsec.md).

## 1. Pré-requisito

Cada FortiGate precisa existir em **Infraestrutura → Equipamentos** (o nome igual ao `hostname` facilita: o import
reconhece sozinho).

## 2. Pegar a configuração

Na CLI do FortiGate (SSH ou o console do painel web). `full-configuration` traz também os valores padrão, o que deixa
o diagnóstico exato:

```
show full-configuration vpn ipsec phase1-interface
show full-configuration vpn ipsec phase2-interface
show firewall address
show firewall addrgrp
show router static
show system sdwan
show system interface
```

Com VDOMs: `config vdom` → `edit <vdom>` antes dos comandos (e `config global` → `show system interface`). Ou use o
backup completo: *System → Configuration → Backup* (sem criptografia) e abra o `.conf` na tela de importação.

```bash
# alternativa por SSH, salvando direto num arquivo
ssh admin@IP-DO-FORTIGATE 'show full-configuration vpn ipsec phase1-interface
show full-configuration vpn ipsec phase2-interface
show firewall address
show firewall addrgrp
show router static' > fgt-cvl-vpn.txt
```

## 3. Importar

**VPN → Importar do FortiGate** → escolher o equipamento → colar (ou abrir o arquivo) → **Pré-visualizar** →
**Importar**. A prévia mostra o que é novo, alterado, igual e o que saiu da config.

> **A PSK não é guardada.** `set psksecret ENC …`, senhas e chaves privadas são descartadas ao ler; o texto colado
> não é gravado nem entra na auditoria. Em **Documentar**, informe só *onde* a chave está (ex.: "Cofre TI › VPN › Cascavel").

Importe os dois lados (matriz e filial): o sistema casa os túneis pelos IPs (remote-gw de um = IP local do outro).
WAN com DHCP/PPPoE não tem IP na config — informe o "IP público local" em **Documentar** ou escolha o par à mão.

## 4. O que a tela mostra

- **Fase 1 lado a lado** com o outro FortiGate (amarelo = configurado diferente).
- **Diagnóstico**: IKE, autenticação, proposta e DH com interseção, PFS, keylife e **seletores da fase 2 espelhados**
  — a causa mais comum de "fase 1 sobe, fase 2 não".
- **Redes do túnel**: seletores e rotas estáticas, com o prefixo do IPAM, filial e VLAN de cada uma.
- **Troubleshooting**: comandos prontos (`diagnose vpn ike gateway list`, filtro de debug pelo IP do peer, sniffer).

## 5. Diagnóstico rápido na CLI

```
get vpn ipsec tunnel summary
diagnose vpn ike gateway list name VPN-CVL
diagnose vpn tunnel list name VPN-CVL
diagnose debug reset
diagnose vpn ike log filter rem-addr4 187.45.60.10
diagnose debug application ike -1
diagnose debug console timestamp enable
diagnose debug enable
# ... reproduzir ...
diagnose debug disable
diagnose debug reset
```

| Sintoma no debug | Causa provável | Onde olhar na tela |
|---|---|---|
| `no SA proposal chosen` (fase 1) | proposta/DH sem interseção | Diagnóstico → Fase 1 |
| `peer SA proposal not match local policy` | proposta da fase 2 / PFS | Diagnóstico → Fase 2 |
| `TS_UNACCEPTABLE` / `quick mode selectors` | seletores não espelhados | Diagnóstico → seletores |
| `negotiation timeout` | UDP 500/4500 bloqueado, remote-gw errado | IPs lado a lado |
| `authentication failed` | PSK diferente | (PSK não fica no sistema — conferir no cofre) |

## 6. Reimportar

Mudou algo no FortiGate? Importe de novo: parâmetros atualizam, documentação é preservada, túnel removido fica
"fora da última config" (mude para *Em desativação* ou exclua).
