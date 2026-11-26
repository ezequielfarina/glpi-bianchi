#!/usr/bin/env bash
# Envia um inventário JSON ao servidor exatamente como o GLPI Agent faz:
#   1) CONTACT  (JSON, zlib)  → espera {"status":"ok","tasks":{"inventory":...}}
#   2) INVENTORY (JSON, zlib) → espera {"status":"ok","expiration":24}
#
# Uso: tools/send-inventory.sh <arquivo.json> [url]
#   tools/send-inventory.sh tests/Fixtures/inventory/computer-linux.json http://127.0.0.1/
set -euo pipefail

FILE="${1:?uso: $0 <arquivo.json> [url]}"
URL="${2:-http://127.0.0.1/}"
AGENT_ID="${AGENT_ID:-00000000-0000-4000-8000-$(printf '%012d' $$)}"
UA="GLPI-Agent_v1.11 (send-inventory.sh)"

command -v php >/dev/null || { echo "php é necessário (compressão zlib)"; exit 1; }
DEVICE_ID="$(php -r 'echo json_decode(file_get_contents($argv[1]), true)["deviceid"] ?? "";' "$FILE")"

post() { # $1 = JSON
    php -r 'echo gzcompress($argv[1]);' "$1" \
    | curl -sS --fail-with-body -X POST "$URL" \
        -H 'Content-Type: application/x-compress-zlib' \
        -H "GLPI-Agent-ID: ${AGENT_ID}" \
        -H "User-Agent: ${UA}" \
        --data-binary @- \
    | php -r '$b = stream_get_contents(STDIN); $d = @gzuncompress($b); echo ($d === false ? $b : $d), PHP_EOL;'
}

echo "→ CONTACT  ${URL}  (deviceid=${DEVICE_ID})"
post "{\"action\":\"contact\",\"deviceid\":\"${DEVICE_ID}\",\"name\":\"GLPI-Agent\",\"version\":\"1.11\",\"enabled-tasks\":[\"inventory\",\"netdiscovery\"]}"

echo "→ INVENTORY $(wc -c < "$FILE") bytes"
post "$(cat "$FILE")"

echo "✓ Enviado. O worker processa em segundos: journalctl -u 'bianchi-worker@*' -n 20"
