# Criptografia — operação (ADR-0019)

## O que é cifrado

| Dado | Onde | Algoritmo |
|---|---|---|
| Disco do CT (banco, WAL, logs, anexos, tudo) | host Proxmox | ZFS native encryption `aes-256-gcm` |
| Segredo 2FA, chave de licença, community SNMP, destino de notificação | colunas no banco | XChaCha20-Poly1305 + contexto (`SecretBox` v2) |
| Inventário bruto do agente | `raw_inventory.payload_sealed` | zlib + XChaCha20-Poly1305 |
| Anexos (NF, contratos, fotos) | `ATTACHMENTS_DIR` | secretstream XChaCha20-Poly1305, chave por arquivo (envelope) |
| Backups | `/var/backups/glpi-bianchi/*.age` | age (X25519 + ChaCha20-Poly1305) para chave pública |
| Senhas | `app_account.password_hash` | Argon2id (hash) |
| Sessões | `user_session.token_hash` | SHA-256 do token de 256 bits |
| Rede | painel, API, agente | TLS 1.2/1.3 AEAD |

Busca, IPAM e relatórios continuam funcionando porque os demais campos ficam legíveis **para a aplicação**
e cifrados **no disco** (camada ZFS). Ver ADR-0019 para o porquê de não cifrar coluna a coluna.

## Chave mestra

- `DATA_ENCRYPTION_KEY` no `/opt/glpi-bianchi/.env.local` (0600, usuário `bianchi`). **Guarde uma cópia no cofre, fora do CT.**
- Perder a chave = perder anexos, inventários brutos, 2FA e licenças cifrados.

### Girar a chave (sem parar o sistema)

```bash
cd /opt/glpi-bianchi
sudo bash deploy/lxc/rotate-secrets.sh      # gira esta e todos os outros segredos
# ou só a chave de dados:
OLD=$(sed -n 's/^DATA_ENCRYPTION_KEY=//p' .env.local)
sed -i "s|^DATA_ENCRYPTION_KEY=.*|DATA_ENCRYPTION_KEY=$(openssl rand -base64 32)|" .env.local
sed -i '/^DATA_ENCRYPTION_KEYS_OLD=/d' .env.local && echo "DATA_ENCRYPTION_KEYS_OLD=$OLD" >> .env.local
sudo -u bianchi php bin/console app:crypto:rotate
sudo -u bianchi php bin/console app:crypto:rotate --dry-run --check && sed -i 's/^DATA_ENCRYPTION_KEYS_OLD=.*/DATA_ENCRYPTION_KEYS_OLD=/' .env.local
```

## Backup cifrado

```bash
# no SEU computador / cofre (nunca no CT):
age-keygen -o glpi-bianchi-backup.key
grep 'public key' glpi-bianchi-backup.key            # age1....
# no CT:
echo 'age1....' > /etc/glpi-bianchi/backup.age.pub
systemctl start bianchi-backup.service && journalctl -u bianchi-backup -n 5
ls -lh /var/backups/glpi-bianchi/
```

Restaurar (em outra máquina com a chave privada):

```bash
age -d -i glpi-bianchi-backup.key db-AAAAMMDD-HHMM.dump.age | pg_restore -d bianchi --clean --if-exists --no-owner
age -d -i glpi-bianchi-backup.key files-AAAAMMDD-HHMM.tar.age | tar -x -C /     # anexos + .env.local (chaves)
```

Copie `/var/backups/glpi-bianchi` para fora do host (PBS, NAS, nuvem) — já está cifrado.

## Disco do CT — ZFS no host Proxmox

No host (`pve05`), crie um dataset cifrado e mova o disco do CT para ele:

```bash
# 1. chave do dataset (guarde no cofre; sem ela o CT não sobe após reboot)
mkdir -p /root/keys && chmod 700 /root/keys
openssl rand -out /root/keys/zfs-cifrado.key 32 && chmod 400 /root/keys/zfs-cifrado.key
# 2. dataset cifrado
zfs create -o encryption=aes-256-gcm -o keyformat=raw -o keylocation=file:///root/keys/zfs-cifrado.key rpool/cifrado
pvesm add zfspool cifrado -pool rpool/cifrado -content rootdir,images
# 3. mover o volume do CT 500 (desligue antes ou use --delete após conferir)
pct shutdown 500
pct move-volume 500 rootfs cifrado --delete
pct start 500
# 4. carregar a chave no boot do host
cat >/etc/systemd/system/zfs-load-key-cifrado.service <<'UNIT'
[Unit]
Before=pve-guests.service
After=zfs-import.target
[Service]
Type=oneshot
ExecStart=/usr/sbin/zfs load-key rpool/cifrado
[Install]
WantedBy=zfs-mount.service
UNIT
systemctl enable zfs-load-key-cifrado.service
zfs get encryption,keystatus rpool/cifrado
```

Chave em arquivo no próprio host protege contra **disco/backup/snapshot roubado** (o objetivo). Para proteger
também contra roubo do servidor inteiro, use `keylocation=prompt` (digitar a frase no boot) ou TPM/Clevis.

## Agentes com credencial

```bash
# CT: .env.local
AGENT_BASIC_AUTH=agente:<senha longa>
sudo systemctl restart php8.4-fpm
```

No GLPI Agent: opções `server = https://192.168.110.55/`, `user = agente`, `password = <senha>`
(agent.cfg no Linux; no instalador MSI do Windows, as propriedades equivalentes `SERVER`, `USER`, `PASSWORD`).
Com certificado auto-assinado, aponte o agente para a CA (`ca-cert-file`) em vez de desligar a verificação TLS —
confira o nome exato da opção na documentação da sua versão do agente.
