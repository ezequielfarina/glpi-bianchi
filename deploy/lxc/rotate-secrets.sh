#!/usr/bin/env bash
# Rotação de TODOS os segredos do GLPI Bianchi (auditoria 2026-11, achado crítico C1).
#
# Use quando houver suspeita de vazamento — em especial se o CT rodou com APP_ENV=dev e o
# /_profiler ficou acessível (expunha API_TOKEN, DATA_ENCRYPTION_KEY, senha do banco, APP_SECRET
# e cookies de sessão). Idempotente; pode rodar a qualquer momento.
#
#   sudo bash deploy/lxc/rotate-secrets.sh            # gira tudo
#   sudo bash deploy/lxc/rotate-secrets.sh --dry-run  # só mostra o que faria
#
# O que faz:
#   1. APP_SECRET novo
#   2. API_TOKEN novo (atualize as integrações que usam a API)
#   3. senha nova do usuário do banco (ALTER ROLE) + DATABASE_URL
#   4. DATA_ENCRYPTION_KEY nova; a antiga vai para DATA_ENCRYPTION_KEYS_OLD; tudo é regravado
#      com a nova (app:crypto:rotate) e só então a antiga é removida
#   5. encerra TODAS as sessões do painel (todo mundo entra de novo)
#   6. reinicia PHP-FPM e workers
# Depois: troque a senha das contas de administrador e copie o .env.local novo para o cofre.
set -euo pipefail
umask 077
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
APP_USER="${APP_USER:-bianchi}"
DB_USER="${DB_USER:-bianchi}"
PHP_VER="${PHP_VER:-8.4}"
ENV_LOCAL="$APP_DIR/.env.local"
DRY=0; [[ "${1:-}" == "--dry-run" ]] && DRY=1

[[ $EUID -eq 0 ]] || { echo "Rode como root." >&2; exit 1; }
[[ -f "$ENV_LOCAL" ]] || { echo "Sem $ENV_LOCAL" >&2; exit 1; }
console() { runuser -u "$APP_USER" -- env HOME="$APP_DIR/var" php "$APP_DIR/bin/console" --no-ansi "$@"; }
setvar() { # setvar NOME valor (substitui ou acrescenta, sem expor o valor no ps)
    local name="$1" value="$2" tmp
    tmp="$(mktemp "$ENV_LOCAL.XXXX")"
    grep -v "^${name}=" "$ENV_LOCAL" > "$tmp" || true
    printf '%s=%s\n' "$name" "$value" >> "$tmp"
    chown "$APP_USER:$APP_USER" "$tmp"; chmod 0600 "$tmp"; mv "$tmp" "$ENV_LOCAL"
}

echo "== Rotação de segredos em $APP_DIR $( ((DRY)) && echo '(simulação)')"
if ((DRY)); then
    console app:crypto:rotate --dry-run | tail -n 14
    echo "Sessões ativas: $(runuser -u postgres -- psql -tAd "$(sed -nE 's#^DATABASE_URL="?postgresql://[^/]+/([^?"]+).*#\1#p' "$ENV_LOCAL")" -c 'SELECT count(*) FROM user_session WHERE revoked_at IS NULL AND expires_at > now()')"
    exit 0
fi

cp -a "$ENV_LOCAL" "$ENV_LOCAL.bak-$(date +%Y%m%d%H%M%S)"   # backup local (0600); apague após guardar no cofre

# 1-2
setvar APP_SECRET "$(openssl rand -hex 32)"
setvar API_TOKEN "$(openssl rand -hex 32)"

# 3. banco
URL="$(sed -nE 's#^DATABASE_URL="?([^"]+)"?#\1#p' "$ENV_LOCAL")"
DB_PASS_NEW="$(openssl rand -hex 24)"
runuser -u postgres -- psql -q -v ON_ERROR_STOP=1 -v u="$DB_USER" -v p="$DB_PASS_NEW" <<'SQL'
ALTER ROLE :"u" WITH PASSWORD :'p';
SQL
setvar DATABASE_URL "\"$(sed -E "s#^(postgresql://[^:]+:)[^@]+@#\1${DB_PASS_NEW}@#" <<<"$URL")\""
[[ -f "$APP_DIR/.env.test.local" ]] && sed -i -E "s#^(DATABASE_URL=\"postgresql://[^:]+:)[^@]+@#\1${DB_PASS_NEW}@#" "$APP_DIR/.env.test.local"

# 4. chave de dados: nova vira atual, antiga só decifra durante a regravação
OLD_KEY="$(sed -n 's/^DATA_ENCRYPTION_KEY=//p' "$ENV_LOCAL")"
setvar DATA_ENCRYPTION_KEY "$(openssl rand -base64 32)"
setvar DATA_ENCRYPTION_KEYS_OLD "$OLD_KEY"
console cache:clear >/dev/null
console app:crypto:rotate | tail -n 14
if ! console app:crypto:rotate --dry-run --check >/dev/null; then
    echo "ATENÇÃO: ainda há dados com a chave antiga — DATA_ENCRYPTION_KEYS_OLD mantida. Veja a saída acima." >&2
else
    setvar DATA_ENCRYPTION_KEYS_OLD ""
fi

# 5. sessões
console dbal:run-sql "UPDATE user_session SET revoked_at = now() WHERE revoked_at IS NULL" >/dev/null 2>&1 \
  || runuser -u postgres -- psql -qd "$(sed -nE 's#.*/([^/?"]+)\?.*#\1#p' <<<"$URL")" -c "UPDATE user_session SET revoked_at = now() WHERE revoked_at IS NULL"

# 6. serviços
console cache:clear >/dev/null
systemctl restart "php${PHP_VER}-fpm"
systemctl restart 'bianchi-worker@*' bianchi-scan-worker 2>/dev/null || true

cat <<EOF

Rotação concluída. Agora:
  - todos precisam entrar de novo; troque a senha das contas de administrador;
  - atualize as integrações com o novo API_TOKEN (em $ENV_LOCAL);
  - copie o $ENV_LOCAL novo para o cofre e apague $ENV_LOCAL.bak-* depois;
  - guarde a DATA_ENCRYPTION_KEY antiga no cofre até o próximo backup completo (backups antigos dependem dela).
EOF
