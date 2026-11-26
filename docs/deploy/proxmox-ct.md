# Deploy em CT LXC no Proxmox

Alvo: **Debian 13 (trixie)**, CT unprivileged, serviços nativos com systemd. Ver [ADR-0006](../adr/0006-deploy-ct-lxc.md).

## 1. Criar o CT (host PVE)

```bash
pveam update && pveam available --section system | grep debian-13
pveam download local debian-13-standard_13.1-2_amd64.tar.zst   # use a versão listada

CTID=500; STO=local-lvm; BR=vmbr0
pct create $CTID local:vztmpl/debian-13-standard_13.1-2_amd64.tar.zst \
  --hostname glpi-bianchi --unprivileged 1 --features nesting=1 \
  --cores 2 --memory 4096 --swap 1024 --rootfs $STO:32 \
  --net0 name=eth0,bridge=$BR,ip=192.168.X.50/24,gw=192.168.X.1 \
  --nameserver 192.168.X.1 --timezone America/Sao_Paulo --onboot 1 --start 1
```

| Recurso | Lab | Produção (~2.000 ativos) |
|---|---|---|
| vCPU | 2 | 4 |
| RAM | 4 GB | 8 GB (ajuste `shared_buffers` e workers) |
| Disco | 32 GB | 80 GB+ (payload bruto e histórico crescem) |

## 2. Instalar

```bash
pct enter 500
apt update && apt install -y git
git clone https://github.com/ezequielfarina/glpi-bianchi.git /opt/glpi-bianchi
cd /opt/glpi-bianchi
bash deploy/lxc/install.sh --env=dev        # lab (com ferramentas de teste)
# bash deploy/lxc/install.sh --workers=4    # produção
```

O que o `install.sh` faz (idempotente):

| Etapa | Detalhe |
|---|---|
| Pacotes | nginx, postgresql-17, valkey-server, php8.4-fpm + extensões, composer |
| Usuário | `bianchi` (sistema, sem shell), dono de `/opt/glpi-bianchi` |
| Segredos | `.env.local` (0640) com `APP_SECRET`, senha do banco e `API_TOKEN` aleatórios, preservados em reexecuções |
| PostgreSQL | role e banco `bianchi` (+ `bianchi_test` em dev), extensões, tuning em `conf.d/bianchi.conf` |
| Valkey | bind localhost, AOF, `noeviction` |
| PHP-FPM | pool `bianchi` (socket `/run/php/bianchi.sock`), limites de memória e upload |
| App | `composer install`, migrations, cache, transports do Messenger |
| nginx | vhost em `/etc/nginx/sites-available/glpi-bianchi.conf` |
| Workers | `bianchi-worker@1..N` (systemd, restart automático) |

## 3. Validar

```bash
make status                                  # todos "active (running)"
make agent-sample HTTP_URL=http://127.0.0.1/ # simula agente
make logs                                    # ver o worker processando
make test                                    # (apenas --env=dev)
```

## 4. Atualizar

```bash
cd /opt/glpi-bianchi && make update      # pull + deps + migrations + cache + reload
# ou, se mudou deploy/lxc/*: bash deploy/lxc/install.sh
```
**Antes:** `pct snapshot 500 pre-update-$(date +%F)` no host.

## 5. TLS

O agente aceita HTTP, mas em produção use HTTPS:

- **Proxy reverso existente** (FortiGate VIP + SSL offload, ou nginx/HAProxy de borda): mantenha o
  CT em HTTP na rede interna e configure `TRUSTED_PROXIES=<ip-do-proxy>` no `.env.local`.
- **No próprio CT:** `apt install certbot python3-certbot-nginx && certbot --nginx -d inventario.empresa.com.br`.
- Agente com CA interna: `glpi-agent --server https://... --ca-cert-file /caminho/ca.pem`.

## 6. Operação

| Tarefa | Comando |
|---|---|
| Status | `make status` |
| Logs dos workers | `journalctl -fu 'bianchi-worker@*'` |
| Logs HTTP | `tail -f /var/log/nginx/glpi-bianchi.*.log` |
| Fila (tamanho) | `valkey-cli XLEN inventory` |
| Falhas | `php bin/console messenger:failed:show` / `:retry` |
| Escalar workers | `bash deploy/lxc/install.sh --workers=4` |
| Backup lógico | `runuser -u postgres -- pg_dump -Fc bianchi > /var/backups/bianchi-$(date +%F).dump` |
| FPM status (Zabbix) | `curl -s http://127.0.0.1/fpm-status?json` |

## 7. Troubleshooting

| Sintoma | Verificação |
|---|---|
| `certificate signer not trusted` no git/composer/apt | inspeção SSL no firewall: `openssl s_client -connect github.com:443 -servername github.com </dev/null \| openssl x509 -noout -issuer`. Isente os domínios ou instale a CA em `/usr/local/share/ca-certificates/` + `update-ca-certificates` |
| systemd degradado / serviços não sobem | no host: `pct set <CTID> --features nesting=1 && pct reboot <CTID>` |
| Agente recebe 400 | `journalctl -u php8.4-fpm` e o log `inventory`; teste com `glpi-agent --debug --logger=stderr` |
| Agente recebe 413 | aumente `client_max_body_size` no vhost e `post_max_size` no ini |
| Ativo não aparece | `SELECT status, error FROM raw_inventory ORDER BY id DESC LIMIT 5;` e `make logs` |
| Fila crescendo | workers parados (`make status`) ou lentos: aumente `--workers` |
| `ltree` não existe | `runuser -u postgres -- psql bianchi -c 'CREATE EXTENSION ltree'` |
