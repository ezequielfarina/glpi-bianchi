#!/usr/bin/env bash
# Backup cifrado do GLPI Bianchi (ADR-0019).
#
#   banco (pg_dump -Fc) + anexos (já cifrados) + .env.local (chaves!) → arquivos .age
#
# Cifra com age (X25519 + ChaCha20-Poly1305) para a CHAVE PÚBLICA em /etc/glpi-bianchi/backup.age.pub.
# A chave privada NUNCA fica no CT: quem invadir o servidor não consegue abrir backups antigos.
#
# Gerar o par (no seu computador ou cofre, não no CT):
#   age-keygen -o glpi-bianchi-backup.key      # guarde offline (cofre / pendrive em cofre)
#   grep 'public key' glpi-bianchi-backup.key  # age1... → cole em /etc/glpi-bianchi/backup.age.pub
# Restaurar:
#   age -d -i glpi-bianchi-backup.key db-AAAAMMDD-HHMM.dump.age | pg_restore -d bianchi --clean --if-exists
#   age -d -i glpi-bianchi-backup.key files-AAAAMMDD-HHMM.tar.age | tar -x -C /
set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-/opt/glpi-bianchi}"
DEST="${BACKUP_DIR:-/var/backups/glpi-bianchi}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
RECIPIENT_FILE=/etc/glpi-bianchi/backup.age.pub
STAMP="$(date +%Y%m%d-%H%M)"

[[ -s "$RECIPIENT_FILE" ]] || { echo "Sem chave pública em $RECIPIENT_FILE — backup NÃO executado (veja o cabeçalho deste script)." >&2; exit 3; }
command -v age >/dev/null || { echo "age não instalado (apt install age)" >&2; exit 3; }

ENV_LOCAL="$APP_DIR/.env.local"
DB_URL="$(sed -nE 's#^DATABASE_URL="?([^"]+)"?#\1#p' "$ENV_LOCAL")"
FILES_DIR="$(sed -nE 's#^ATTACHMENTS_DIR=(.+)#\1#p' "$ENV_LOCAL")"
FILES_DIR="${FILES_DIR:-/var/lib/glpi-bianchi/files}"
install -d -m 0700 "$DEST"

# banco: formato custom (comprimido); o libpq lê a URL sem expor a senha no ps
PGURL="${DB_URL%%\?*}"
pg_dump --format=custom --no-owner --dbname="$PGURL" | age -R "$RECIPIENT_FILE" -o "$DEST/db-$STAMP.dump.age"

# anexos (já cifrados em repouso) + segredos da aplicação
tar -C / -cf - "${FILES_DIR#/}" "${ENV_LOCAL#/}" 2>/dev/null | age -R "$RECIPIENT_FILE" -o "$DEST/files-$STAMP.tar.age"

# verificação mínima: arquivos não vazios e no formato age
for f in "$DEST/db-$STAMP.dump.age" "$DEST/files-$STAMP.tar.age"; do
    [[ -s "$f" ]] && head -c 21 "$f" | grep -q 'age-encryption.org/v1' || { echo "Backup inválido: $f" >&2; exit 4; }
done

find "$DEST" -name '*.age' -mtime +"$KEEP_DAYS" -delete
echo "Backup ok: $DEST/{db,files}-$STAMP.*.age"
