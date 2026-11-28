#!/usr/bin/env bash
# =============================================================================
# GLPI Bianchi — provisionamento de CT LXC (Proxmox) com Debian 13 "trixie"
#
# Instala e configura, tudo nativo (sem Docker):
#   PostgreSQL 17 · Valkey 8 · PHP 8.4-FPM · nginx · workers systemd do Messenger
#
# Uso (como root, dentro do CT):
#   bash deploy/lxc/install.sh              # produção (APP_ENV=prod, sem deps de dev)
#   bash deploy/lxc/install.sh --env=dev    # laboratório (deps de dev, roda testes)
#   bash deploy/lxc/install.sh --workers=4  # quantidade de workers (padrão 2)
#
# Idempotente: pode ser executado de novo após `git pull` (atualiza código,
# deps, migrations e reinicia serviços). Segredos gerados na 1ª execução são
# preservados em $APP_DIR/.env.local.
# =============================================================================
set -Eeuo pipefail

APP_ENV="prod"
WORKERS=2
for arg in "$@"; do
    case "$arg" in
        --env=*)     APP_ENV="${arg#*=}" ;;
        --workers=*) WORKERS="${arg#*=}" ;;
        -h|--help)   sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "Argumento desconhecido: $arg" >&2; exit 2 ;;
    esac
done
[[ "$APP_ENV" =~ ^(prod|dev)$ ]] || { echo "--env deve ser prod ou dev" >&2; exit 2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
APP_USER="bianchi"
DB_NAME="bianchi"
DB_USER="bianchi"
PHP_VER="8.4"
PG_VER="17"

log()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m[!] %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31m[x] %s\033[0m\n' "$*" >&2; exit 1; }
trap 'die "Falhou na linha $LINENO: $BASH_COMMAND"' ERR

# ----------------------------------------------------------------------------
log "0/9 Pré-checagens"
[[ $EUID -eq 0 ]] || die "Execute como root."
. /etc/os-release
[[ "${VERSION_CODENAME:-}" == "trixie" ]] || die "Requer Debian 13 (trixie); encontrado: ${PRETTY_NAME:-?}"
if [[ -r /proc/1/environ ]] && grep -qa 'container=lxc' /proc/1/environ; then
    systemctl is-system-running --quiet 2>/dev/null || warn "systemd degradado — confirme 'features: nesting=1' no CT."
fi
echo "APP_DIR=$APP_DIR  APP_ENV=$APP_ENV  WORKERS=$WORKERS"

# ----------------------------------------------------------------------------
log "1/9 Pacotes"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q --no-install-recommends \
    ca-certificates curl git unzip openssl locales tzdata acl make jq \
    nginx \
    "postgresql-${PG_VER}" postgresql-contrib \
    valkey-server \
    "php${PHP_VER}-fpm" "php${PHP_VER}-cli" "php${PHP_VER}-pgsql" "php${PHP_VER}-intl" \
    "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" "php${PHP_VER}-zip" "php${PHP_VER}-curl" \
    "php${PHP_VER}-opcache" "php${PHP_VER}-redis" "php${PHP_VER}-apcu" "php${PHP_VER}-snmp" "php${PHP_VER}-gd" \
    composer age \
    nmap iproute2 arp-scan bind9-dnsutils mtr-tiny traceroute iputils-ping \
    fonts-liberation2

timedatectl set-timezone America/Sao_Paulo 2>/dev/null || ln -sf /usr/share/zoneinfo/America/Sao_Paulo /etc/localtime

# ----------------------------------------------------------------------------
log "2/9 Usuário de sistema '$APP_USER'"
if ! id "$APP_USER" &>/dev/null; then
    useradd --system --home-dir "$APP_DIR" --shell /usr/sbin/nologin "$APP_USER"
fi
chown -R "$APP_USER:$APP_USER" "$APP_DIR"
# git como root no diretório do app (git pull no deploy)
git config --system --add safe.directory "$APP_DIR" 2>/dev/null || true
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 "$APP_DIR/var"

# ----------------------------------------------------------------------------
log "3/9 Segredos (.env.local)"
ENV_LOCAL="$APP_DIR/.env.local"
if [[ ! -f "$ENV_LOCAL" ]]; then
    DB_PASS="$(openssl rand -hex 24)"
    cat > "$ENV_LOCAL" <<EOF
# Gerado por deploy/lxc/install.sh em $(date -Iseconds). NÃO versionar.
APP_ENV=${APP_ENV}
APP_DEBUG=$([[ $APP_ENV == dev ]] && echo 1 || echo 0)
APP_SECRET=$(openssl rand -hex 32)
DATABASE_URL="postgresql://${DB_USER}:${DB_PASS}@127.0.0.1:5432/${DB_NAME}?serverVersion=${PG_VER}&charset=utf8"
MESSENGER_TRANSPORT_DSN=redis://127.0.0.1:6379/inventory
API_TOKEN=$(openssl rand -hex 32)
EOF
    echo "Criado $ENV_LOCAL"
else
    echo "Mantido $ENV_LOCAL existente"
    sed -i "s/^APP_ENV=.*/APP_ENV=${APP_ENV}/" "$ENV_LOCAL"
fi
# Variáveis adicionadas em versões posteriores: acrescenta só se faltarem (não sobrescreve)
FILES_DIR=/var/lib/glpi-bianchi/files
grep -q '^ATTACHMENTS_DIR=' "$ENV_LOCAL" || echo "ATTACHMENTS_DIR=${FILES_DIR}" >> "$ENV_LOCAL"
# Chave que cifra as chaves de licença no banco. NUNCA é regenerada se já existir.
grep -q '^DATA_ENCRYPTION_KEY=.\+' "$ENV_LOCAL" || { sed -i '/^DATA_ENCRYPTION_KEY=$/d' "$ENV_LOCAL"; echo "DATA_ENCRYPTION_KEY=$(openssl rand -base64 32)" >> "$ENV_LOCAL"; }
install -d -o "$APP_USER" -g "$APP_USER" -m 0750 /var/lib/glpi-bianchi "$FILES_DIR"
chown "$APP_USER:$APP_USER" "$ENV_LOCAL"
chmod 0640 "$ENV_LOCAL"
DB_PASS="$(sed -nE 's#^DATABASE_URL="postgresql://[^:]+:([^@]+)@.*#\1#p' "$ENV_LOCAL")"
[[ -n "$DB_PASS" ]] || die "Não consegui ler a senha do banco em $ENV_LOCAL"

# APP_ENV=test NÃO carrega .env.local (comportamento do Symfony): o banco de teste
# precisa da mesma credencial em .env.test.local. O sufixo "_test" é aplicado pelo
# doctrine.yaml (dbname_suffix), portanto a URL aponta para o banco base.
if [[ "$APP_ENV" == "dev" ]]; then
    cat > "$APP_DIR/.env.test.local" <<EOF
# Gerado por deploy/lxc/install.sh. NÃO versionar.
DATABASE_URL="postgresql://${DB_USER}:${DB_PASS}@127.0.0.1:5432/${DB_NAME}?serverVersion=${PG_VER}&charset=utf8"
EOF
    chown "$APP_USER:$APP_USER" "$APP_DIR/.env.test.local"
    chmod 0640 "$APP_DIR/.env.test.local"
fi

# ----------------------------------------------------------------------------
log "4/9 PostgreSQL ${PG_VER}"
# Sem restart incondicional: ele derruba (FATAL 57P01) as conexões dos workers e mata a varredura
# em andamento. Config nova → reload; restart só se algum parâmetro alterado exigir (pending_restart).
PG_CONF="/etc/postgresql/${PG_VER}/main/conf.d/bianchi.conf"
PG_CHANGED=0
cmp -s "$SCRIPT_DIR/postgresql/bianchi.conf" "$PG_CONF" || PG_CHANGED=1
install -m 0644 "$SCRIPT_DIR/postgresql/bianchi.conf" "$PG_CONF"
systemctl enable --now "postgresql@${PG_VER}-main"
if [[ "$PG_CHANGED" == 1 ]]; then
    systemctl reload "postgresql@${PG_VER}-main"
    sleep 1
    PG_PENDING="$(runuser -u postgres -- psql -qAt -c "SELECT string_agg(name, ', ') FROM pg_settings WHERE pending_restart" 2>/dev/null || true)"
    if [[ -n "$PG_PENDING" ]]; then
        if [[ -n "$(runuser -u postgres -- psql -qAt -c "SELECT 1 FROM pg_stat_activity WHERE query ILIKE '%network_scan%' AND state <> 'idle' LIMIT 1" 2>/dev/null)" ]]; then
            echo "AVISO: há varredura em andamento; ela será interrompida pelo restart do PostgreSQL" >&2
        fi
        echo "PostgreSQL: restart necessário por ${PG_PENDING}"
        systemctl restart "postgresql@${PG_VER}-main"
    fi
fi

sudo_pg() { runuser -u postgres -- psql -v ON_ERROR_STOP=1 -qAt "$@"; }
if [[ -z "$(sudo_pg -c "SELECT 1 FROM pg_roles WHERE rolname='${DB_USER}'")" ]]; then
    sudo_pg -c "CREATE ROLE ${DB_USER} LOGIN PASSWORD '${DB_PASS}'"
else
    sudo_pg -c "ALTER ROLE ${DB_USER} PASSWORD '${DB_PASS}'"
fi
for db in "$DB_NAME" "${DB_NAME}_test"; do
    if [[ -z "$(sudo_pg -c "SELECT 1 FROM pg_database WHERE datname='${db}'")" ]]; then
        sudo_pg -c "CREATE DATABASE ${db} OWNER ${DB_USER} ENCODING 'UTF8' TEMPLATE template0"
    fi
    sudo_pg -d "$db" -c "CREATE EXTENSION IF NOT EXISTS ltree; CREATE EXTENSION IF NOT EXISTS pg_trgm;"
done
[[ "$APP_ENV" == "dev" ]] || sudo_pg -c "DROP DATABASE IF EXISTS ${DB_NAME}_test"

# ----------------------------------------------------------------------------
log "5/9 Valkey"
VALKEY_CONF=/etc/valkey/valkey.conf
sed -i -E 's/^#?\s*bind .*/bind 127.0.0.1 -::1/' "$VALKEY_CONF"
sed -i -E 's/^#?\s*appendonly .*/appendonly yes/' "$VALKEY_CONF"
# Fila não pode perder mensagens por eviction
if grep -qE '^#?\s*maxmemory-policy' "$VALKEY_CONF"; then
    sed -i -E 's/^#?\s*maxmemory-policy .*/maxmemory-policy noeviction/' "$VALKEY_CONF"
else
    echo 'maxmemory-policy noeviction' >> "$VALKEY_CONF"
fi
systemctl enable --now valkey-server
systemctl restart valkey-server

# ----------------------------------------------------------------------------
log "6/9 PHP ${PHP_VER}-FPM"
install -m 0644 "$SCRIPT_DIR/php/99-bianchi.ini" "/etc/php/${PHP_VER}/fpm/conf.d/99-bianchi.ini"
install -m 0644 "$SCRIPT_DIR/php/99-bianchi.ini" "/etc/php/${PHP_VER}/cli/conf.d/99-bianchi.ini"
if [[ "$APP_ENV" == "prod" ]]; then
    install -m 0644 "$SCRIPT_DIR/php/99-bianchi-prod.ini" "/etc/php/${PHP_VER}/fpm/conf.d/99-bianchi-prod.ini"
else
    rm -f "/etc/php/${PHP_VER}/fpm/conf.d/99-bianchi-prod.ini"
fi
sed "s#@APP_DIR@#${APP_DIR}#g; s#@APP_USER@#${APP_USER}#g" "$SCRIPT_DIR/php/pool.conf" \
    > "/etc/php/${PHP_VER}/fpm/pool.d/bianchi.conf"
rm -f "/etc/php/${PHP_VER}/fpm/pool.d/www.conf"
php-fpm${PHP_VER} -t
systemctl enable --now "php${PHP_VER}-fpm"

# ----------------------------------------------------------------------------
log "7/9 Aplicação (composer, migrations, cache)"
as_app() { runuser -u "$APP_USER" -- env HOME="$APP_DIR/var" COMPOSER_HOME="$APP_DIR/var/composer" "$@"; }
cd "$APP_DIR"
# composer.lock fora de sincronia com composer.json (dependência nova adicionada):
# em dev resolve sozinho com `composer update`; em prod exige o lock versionado correto.
if [[ -f composer.lock ]] && ! as_app composer validate --no-check-publish --no-check-all --quiet >/dev/null 2>&1; then
    if [[ "$APP_ENV" == "prod" ]]; then
        die "composer.lock desatualizado em relação ao composer.json — gere o lock em dev e versione antes do deploy"
    fi
    warn "composer.lock desatualizado — executando composer update (dev)"
    as_app composer update --no-interaction --prefer-dist
fi
if [[ "$APP_ENV" == "prod" ]]; then
    as_app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative
else
    as_app composer install --no-interaction --prefer-dist
fi
as_app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
if [[ "$APP_ENV" == "dev" ]]; then
    as_app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration --env=test
fi
as_app php bin/console cache:clear
as_app php bin/console messenger:setup-transports
# Primeiro administrador do painel (só se ainda não houver nenhuma conta)
ADMIN_FILE=/root/glpi-bianchi-admin.txt
ADMIN_OUT="$(as_app php bin/console app:user:create admin --role=admin --name=Administrador --generate --if-none --no-ansi)"
ADMIN_PASS="$(sed -n 's/^Senha temporária: //p' <<<"$ADMIN_OUT" | head -1)"
if [[ -n "$ADMIN_PASS" ]]; then
    umask 077
    printf 'Painel GLPI Bianchi\nusuário: admin\nsenha temporária: %s\n(troca obrigatória no primeiro login; apague este arquivo depois)\n' "$ADMIN_PASS" > "$ADMIN_FILE"
    umask 022
fi
systemctl reload "php${PHP_VER}-fpm"

# ----------------------------------------------------------------------------
log "8/9 nginx + HTTPS"
# Certificado TLS: gera um auto-assinado (ECDSA P-256, SAN = nome + IP) só se não existir.
# Para usar o da CA interna, substitua cert.pem/key.pem e rode `systemctl reload nginx`.
TLS_DIR=/etc/glpi-bianchi/tls
install -d -m 0750 "$TLS_DIR"
if [[ ! -s "$TLS_DIR/cert.pem" || ! -s "$TLS_DIR/key.pem" ]]; then
    HOST_FQDN="$(hostname -f 2>/dev/null || hostname)"
    HOST_IP="$(hostname -I | awk '{print $1}')"
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 -nodes -days 825 \
        -keyout "$TLS_DIR/key.pem" -out "$TLS_DIR/cert.pem" \
        -subj "/CN=${HOST_FQDN}/O=GLPI Bianchi" \
        -addext "subjectAltName=DNS:${HOST_FQDN},DNS:$(hostname -s),IP:${HOST_IP}" \
        -addext "keyUsage=critical,digitalSignature" -addext "extendedKeyUsage=serverAuth" 2>/dev/null
    chmod 0600 "$TLS_DIR/key.pem"
    echo "Certificado auto-assinado criado em $TLS_DIR (SAN: ${HOST_FQDN}, ${HOST_IP})"
fi
sed "s#@APP_DIR@#${APP_DIR}#g" "$SCRIPT_DIR/nginx/glpi-bianchi-php.conf" > /etc/nginx/snippets/glpi-bianchi-php.conf
sed "s#@APP_DIR@#${APP_DIR}#g; s#@TLS_DIR@#${TLS_DIR}#g" "$SCRIPT_DIR/nginx/glpi-bianchi.conf" > /etc/nginx/sites-available/glpi-bianchi.conf
ln -sf /etc/nginx/sites-available/glpi-bianchi.conf /etc/nginx/sites-enabled/glpi-bianchi.conf
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl enable --now nginx
systemctl reload nginx

# ----------------------------------------------------------------------------
log "9/9 Workers systemd (${WORKERS}x)"
sed "s#@APP_DIR@#${APP_DIR}#g; s#@APP_USER@#${APP_USER}#g; s#@PHP_VER@#${PHP_VER}#g" \
    "$SCRIPT_DIR/systemd/bianchi-worker@.service" > /etc/systemd/system/bianchi-worker@.service
systemctl daemon-reload
# desabilita instâncias acima do número pedido
for unit in $(systemctl list-units --plain --no-legend 'bianchi-worker@*' | awk '{print $1}'); do
    n="${unit#bianchi-worker@}"; n="${n%.service}"
    if (( n > WORKERS )); then systemctl disable --now "$unit"; fi
done
# Scanner de rede: worker único com CAP_NET_RAW (sem root) — ADR-0012
sed "s#@APP_DIR@#${APP_DIR}#g; s#@APP_USER@#${APP_USER}#g; s#@PHP_VER@#${PHP_VER}#g" \
    "$SCRIPT_DIR/systemd/bianchi-scan-worker.service" > /etc/systemd/system/bianchi-scan-worker.service
systemctl daemon-reload
systemctl enable bianchi-scan-worker
systemctl reset-failed bianchi-scan-worker 2>/dev/null || true
RUNNING_SCANS="$(runuser -u postgres -- psql -tAd "$DB_NAME" -c "SELECT string_agg('#'||id||' '||target, ', ') FROM network_scan WHERE status = 'running'" 2>/dev/null || true)"
[[ -n "$RUNNING_SCANS" ]] && warn "Varredura em andamento será interrompida pelo reinício do scanner: $RUNNING_SCANS (agendada roda de novo em 5 min)."
systemctl restart bianchi-scan-worker
# Agendador de varreduras (timer a cada minuto) — ADR-0013
for f in bianchi-scan-scheduler.service bianchi-scan-scheduler.timer; do
    sed "s#@APP_DIR@#${APP_DIR}#g; s#@APP_USER@#${APP_USER}#g; s#@PHP_VER@#${PHP_VER}#g" \
        "$SCRIPT_DIR/systemd/$f" > "/etc/systemd/system/$f"
done
systemctl daemon-reload
systemctl enable --now bianchi-scan-scheduler.timer
# Filtro de MAC do UniFi (timer a cada 10 min) — ADR-0020
for f in bianchi-wifi-sync.service bianchi-wifi-sync.timer; do
    sed "s#@APP_DIR@#${APP_DIR}#g; s#@APP_USER@#${APP_USER}#g; s#@PHP_VER@#${PHP_VER}#g" \
        "$SCRIPT_DIR/systemd/$f" > "/etc/systemd/system/$f"
done
systemctl daemon-reload
systemctl enable --now bianchi-wifi-sync.timer
# Diagnóstico (ping sem root para o PHP-FPM): ICMP datagram socket liberado para todos os grupos.
# O sysctl é por namespace de rede (vale só dentro do CT) e a faixa precisa caber no gid_map do
# user namespace: em CT unprivileged o máximo é 65535 — "0 2147483647" ali dá EINVAL.
PING_GID_MAX="$(awk '{e=$1+$3-1; if (e>m) m=e} END {if (m>2147483647) m=2147483647; print m+0}' /proc/self/gid_map)"
echo "net.ipv4.ping_group_range = 0 ${PING_GID_MAX}" > /etc/sysctl.d/60-bianchi-ping.conf
if ! sysctl -q -p /etc/sysctl.d/60-bianchi-ping.conf; then
    echo "AVISO: não foi possível ajustar net.ipv4.ping_group_range; aplicando cap_net_raw no ping" >&2
    setcap cap_net_raw+ep "$(command -v ping)" || echo "AVISO: setcap falhou — ping do Diagnóstico ficará indisponível" >&2
fi
# Backup cifrado diário (ADR-0019): só roda com a chave pública em /etc/glpi-bianchi/backup.age.pub
install -d -m 0750 /etc/glpi-bianchi /var/backups/glpi-bianchi
for f in bianchi-backup.service bianchi-backup.timer; do
    sed "s#@APP_DIR@#${APP_DIR}#g" "$SCRIPT_DIR/systemd/$f" > "/etc/systemd/system/$f"
done
systemctl daemon-reload
systemctl enable --now bianchi-backup.timer
[[ -s /etc/glpi-bianchi/backup.age.pub ]] || warn "Backup cifrado inativo: falta /etc/glpi-bianchi/backup.age.pub (ver deploy/lxc/backup.sh)."
# Dados antigos → cifrados; segredos regravados com a chave atual (idempotente — ADR-0019)
as_app php bin/console app:crypto:rotate --no-interaction --no-ansi | tail -n 12 || warn "app:crypto:rotate falhou — rode manualmente e veja o erro."
# .env.local guarda as chaves: só o usuário da aplicação lê
chmod 0600 "$ENV_LOCAL"
for i in $(seq 1 "$WORKERS"); do
    systemctl enable "bianchi-worker@${i}"
    # zera o StartLimitBurst de falhas anteriores (ex.: deploy quebrado antes deste)
    systemctl reset-failed "bianchi-worker@${i}" 2>/dev/null || true
    systemctl restart "bianchi-worker@${i}"
done

# ----------------------------------------------------------------------------
IP="$(hostname -I | awk '{print $1}')"
API_TOKEN="$(sed -n 's/^API_TOKEN=//p' "$ENV_LOCAL")"
cat <<EOF

$(printf '\033[1;32m')Instalação concluída.$(printf '\033[0m')

  Painel                 : https://${IP}/app/
$( [[ -n "${ADMIN_PASS:-}" ]] && printf '  Primeiro acesso        : usuário \033[1madmin\033[0m, senha temporária \033[1m%s\033[0m\n                           (também em %s — apague depois do 1º login)\n' "$ADMIN_PASS" "$ADMIN_FILE" )
  Certificado            : ${TLS_DIR}/cert.pem (auto-assinado: o navegador avisa na 1ª vez;
                           para tirar o aviso use um certificado da sua CA — ver docs/deploy)
  Endpoint do GLPI Agent : http://${IP}/  (ou https://${IP}/)
  API de integração      : https://${IP}/api/docs  (Bearer ${API_TOKEN:0:6}… — ver ${ENV_LOCAL})

  Recuperar acesso       : cd ${APP_DIR} && sudo -u ${APP_USER} php bin/console app:user:recover admin --password

  Teste rápido:
    cd ${APP_DIR} && make agent-sample HTTP_URL=http://127.0.0.1/

  Agente (em uma máquina de teste):
    glpi-agent --server http://${IP}/ --force --debug

  Anexos (fotos, contratos): /var/lib/glpi-bianchi/files
  Criptografia (ADR-0019): anexos, inventários brutos, 2FA, licenças e SNMP cifrados com DATA_ENCRYPTION_KEY.
  IMPORTANTE: guarde o .env.local FORA do CT (cofre) — sem DATA_ENCRYPTION_KEY os dados cifrados se perdem.
  Backup cifrado diário: $( [[ -s /etc/glpi-bianchi/backup.age.pub ]] && echo "ativo em /var/backups/glpi-bianchi" || echo "INATIVO — crie /etc/glpi-bianchi/backup.age.pub (deploy/lxc/backup.sh)" )

  Operação: make status | make logs | make update
EOF
