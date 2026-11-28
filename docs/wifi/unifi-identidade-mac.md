# Wi-Fi: identidade de MAC no UniFi

O filtro de MAC do UniFi (*Settings → WiFi → rede → Advanced → MAC Address Filter*) só guarda a lista de endereços.
No GLPI Bianchi cada MAC tem **pessoa, aparelho, patrimônio, validade e redes**; ao aplicar, o painel atualiza o filtro
da rede no UniFi pela API. Decisão e alternativas: [ADR-0020](../adr/0020-identidade-mac-unifi.md).

```
GLPI Bianchi (CT 192.168.110.55) ──HTTPS──▶ VM do UniFi Network (:8443 clássico / :11443 UniFi OS Server)
                                              ▲
                     unifi.ui.com ────────────┘ (gestão remota; a API da nuvem é só leitura)
```

O CT fala **direto com a VM**: a API do `api.ui.com` (Site Manager) não altera configuração de Wi-Fi.

## 1. Preparar o UniFi (na VM)

Descubra a versão e a porta:

```bash
# na VM do controller
dpkg -l | grep -E 'unifi|uos'                    # unifi = controller clássico (8443) · uos-server = UniFi OS Server (11443)
ss -ltnp | grep -E ':8443|:11443|:443'
curl -kI https://127.0.0.1:8443/                 # 302 → /manage = controller clássico
curl -kI https://127.0.0.1:11443/                # 200 = UniFi OS
```

Crie a credencial da integração (uma das duas):

| Plataforma | Credencial | Onde |
|---|---|---|
| Controller clássico (8443) | **admin local** | *Settings → Admins & Users → Create New Admin* → "Restrict to local access only", papel *Site Admin* (todos os sites) ou *Super Admin*, **sem 2FA** |
| UniFi OS Server / console (11443 / 443) | **API key** (recomendado) | *Settings → Control Plane → Integrations → Create API Key* |

> Use um usuário só para o GLPI Bianchi (`glpi-bianchi`): a trilha do UniFi mostra o que foi feito pela integração e
> você revoga sem afetar ninguém. Conta da ui.com com 2FA **não** funciona por API.

## 2. Liberar o caminho CT → VM

```bash
# no CT
curl -skI https://IP-DA-VM:8443/ | head -1        # precisa responder (302 ou 200)
openssl s_client -connect IP-DA-VM:8443 </dev/null 2>/dev/null | openssl x509 -noout -fingerprint -sha256
```

FortiGate entre a VLAN do CT e a da VM:

```
config firewall address
    edit "CT-GLPI-BIANCHI"
        set subnet 192.168.110.55 255.255.255.255
    next
    edit "VM-UNIFI"
        set subnet IP-DA-VM 255.255.255.255
    next
end
config firewall service custom
    edit "UNIFI-API"
        set tcp-portrange 8443 11443
    next
end
config firewall policy
    edit 0
        set name "GLPI-Bianchi-para-UniFi-API"
        set srcintf "VLAN-SERVIDORES"
        set dstintf "VLAN-GERENCIA"
        set srcaddr "CT-GLPI-BIANCHI"
        set dstaddr "VM-UNIFI"
        set action accept
        set schedule "always"
        set service "UNIFI-API"
        set logtraffic all
    next
end
```

## 3. Cadastrar no painel

1. **Wi-Fi (UniFi) → Controllers UniFi → Novo controller** (só administrador).
2. Endereço `https://IP-DA-VM:8443` → **Ler certificado** → confira o SHA-256 com o `openssl` do passo 2 → **Fixar este certificado**.
   O certificado autoassinado do UniFi é aceito só com aquela chave pública; se mudar, a conexão é recusada (proteção contra interceptação).
   Renovou o certificado? Edite o controller e fixe de novo.
3. Usuário/senha do admin local (ou a API key) → **Salvar e testar** → **Sincronizar**.
4. **Wi-Fi → Acesso por MAC**: os sites (`Matriz`, `e6ovl2sz`…) e as redes aparecem na árvore.

## 4. Uso no dia a dia

- **Cadastrar MAC**: MAC em qualquer formato, pessoa, aparelho, validade e redes. "Aplicar no UniFi agora" grava na hora;
  desmarcado, vai na próxima sincronização (até 10 min).
- **Sem identificação**: MACs que já estavam no filtro antes do painel. Continuam liberados; clique em **Identificar** para dar nome.
- **Revogar**: tira o MAC de todas as redes. **Validade** vencida faz o mesmo automaticamente.
- **Estados**: *No UniFi* · *Aguardando aplicar* (controller fora do ar → nova tentativa a cada 10 min) · *Com erro* (por exemplo,
  alguém removeu o MAC direto no UniFi — **Conferir e aplicar** recoloca; ou revogue) · *Saindo*.
- **Ligar filtro** (administrador): se a rede está com o filtro desligado, a lista é gravada mas não barra ninguém. Cadastre/identifique
  todos os aparelhos legítimos e só então ligue — pede o nome exato da rede.
- Redes com filtro em modo **bloqueio** (*deny*) não são gerenciadas: incluir um MAC ali bloquearia o aparelho.

### O que o painel envia ao UniFi

Só `mac_filter_enabled`, `mac_filter_policy` (os valores que já estavam) e `mac_filter_list`, num `PUT` parcial em
`/api/s/<site>/rest/wlanconf/<id>`. Senha, rádios e VLAN do Wi-Fi não são enviados. A lista é
`(lista atual − MACs que o painel retira) ∪ MACs ativos do painel`, com trava por rede e releitura para conferir.
Limite: 512 MACs por rede.

## 5. Operação e troubleshooting

```bash
# no CT
systemctl list-timers bianchi-wifi-sync.timer
journalctl -u bianchi-wifi-sync -n 50 --no-pager
sudo -u bianchi php /opt/glpi-bianchi/bin/console app:wifi:sync -v          # sincroniza e aplica pendências agora
sudo -u bianchi php /opt/glpi-bianchi/bin/console app:wifi:sync --no-sync    # só pendências/vencimentos
journalctl -u bianchi-wifi-sync --since today | grep -iE "falhou|erro"
```

| Mensagem | Causa / ação |
|---|---|
| Conexão recusada / tempo esgotado | porta errada (8443 × 11443 × 443) ou firewall; teste `curl -kI` do CT |
| Login no UniFi recusado (HTTP 400/401) | use admin **local**, sem 2FA; senha trocada → edite o controller |
| HTTP 429/499 no login | bloqueio por tentativas no UniFi: aguarde alguns minutos |
| sem permissão (403) | o admin da integração precisa ser administrador do site (ou de todos os sites) |
| certificado MUDOU em relação ao fixado | certificado/chave renovados → fixe de novo; se você não renovou, investigue (MITM) |
| lista lida de volta não confere | alguém alterou a rede no mesmo instante: sincronize e aplique de novo |
| MAC aleatório | iOS/Android/Windows usam endereço privado por rede: desligue "endereço privado/aleatório" para essa rede no aparelho |

```sql
-- quem está liberado em cada rede, com o estado no UniFi
SELECT w.name AS rede, coalesce(s.description, s.site_ref) AS site, i.mac, i.person_name, i.department, a.state, i.valid_until
FROM mac_wlan_assignment a JOIN mac_identity i ON i.id = a.identity_id
JOIN unifi_wlan w ON w.id = a.wlan_id JOIN unifi_site s ON s.id = w.site_id
ORDER BY site, rede, i.person_name;

-- vencendo nos próximos 7 dias
SELECT mac, person_name, valid_until FROM mac_identity WHERE status = 'active' AND valid_until < now() + interval '7 days' ORDER BY valid_until;
```

## Limites de segurança

Filtro de MAC **não é autenticação**: o MAC aparece em claro no ar e é clonável. Ele serve para controle e
rastreabilidade em redes PSK. Para a rede corporativa, o caminho é **WPA2/3-Enterprise (802.1X)** com RADIUS —
`mac_identity` já tem os dados para alimentar um FreeRADIUS (MAC auth / `users`) numa próxima etapa.
