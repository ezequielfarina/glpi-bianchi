# ADR-0019: Criptografia em repouso e em trânsito

- **Status:** aceito
- **Data:** 2026-11-28
- **Decisores:** Ezequiel Farina

## Contexto

A auditoria de segurança de 2026-11 (docs/security/auditoria-2026-11.md) pediu que **todos os dados** fiquem
criptografados com algoritmo forte. Já eram cifrados (XSalsa20-Poly1305, sem contexto e sem rotação de
chave): segredo do 2FA, chave de licença, community SNMP e destino das notificações. Ficavam em claro:
anexos (notas fiscais, contratos, fotos), o payload bruto do inventário (retrato completo de cada máquina),
o banco no disco, os backups (que não existiam).

Cifrar *cada coluna* na aplicação quebra o que o sistema faz: busca por nome/serial (`pg_trgm`), hierarquia de
prefixos IP (`inet <<`), ordenação, índices GiST, relatórios no Grafana. Criptografia com busca exigiria
esquemas determinísticos (que vazam padrões) ou índices cegos — complexidade sem ganho real contra o modelo
de ameaça abaixo.

**Modelo de ameaça:** (1) roubo/vazamento do disco, snapshot ou backup do CT; (2) leitura do banco por
SQL injection ou credencial vazada; (3) interceptação na rede; (4) adulteração de dados cifrados.

## Decisão

Criptografia em **camadas**, cada uma cobrindo uma ameaça:

| Camada | O que cobre | Como |
|---|---|---|
| Disco do CT (host Proxmox) | **tudo**: banco, WAL, índices, temporários, logs, anexos | ZFS native encryption `aes-256-gcm` no dataset do CT (docs/security/criptografia.md) — ameaça 1 |
| Aplicação — colunas sensíveis | 2FA, licenças, SNMP, notificações | `SecretBox` v2: **XChaCha20-Poly1305** (libsodium), chave 256 bits, nonce 192 bits, **AAD = contexto** (`account.totp:<id>`, `license.key`…) — ameaças 2 e 4 |
| Aplicação — inventário bruto | `raw_inventory.payload_sealed` | tipo Doctrine `sealed_json`: JSON → zlib → SecretBox |
| Aplicação — anexos | arquivos em `ATTACHMENTS_DIR` | `FileCipher`: secretstream XChaCha20-Poly1305 em blocos de 64 KiB; **chave aleatória por arquivo** guardada cifrada no banco (envelope) |
| Backups | banco + anexos + `.env.local` | `age` (X25519 + ChaCha20-Poly1305) para **chave pública**; a privada fica fora do CT |
| Rede | painel, API, agente | TLS 1.2/1.3 só com AEAD (já existente); HSTS quando houver certificado de CA; agente restrito a redes privadas + Basic opcional |
| Senhas | contas do painel | Argon2id (já existente) — hash, não cifra |
| Sessões | cookie | token 256 bits; no banco só SHA-256 (já existente) |

**Chaves:** `DATA_ENCRYPTION_KEY` (atual) e `DATA_ENCRYPTION_KEYS_OLD` (só decifram). Cada valor leva o
`kid` da chave que o cifrou; `bin/console app:crypto:rotate` regrava tudo com a atual (idempotente,
`--dry-run` mostra o pendente). Derivações por uso via BLAKE2b (`SecretBox::derive`). O formato antigo
continua legível e é migrado pelo mesmo comando (o `install.sh` o executa a cada deploy).

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| pgcrypto (`pgp_sym_encrypt`) no SQL | transparente nas consultas | chave trafega no SQL e aparece em logs/`pg_stat_statements`; extensão fora da lista permitida |
| TDE do PostgreSQL | tudo cifrado | não existe no PostgreSQL 17 comunitário (só forks comerciais) |
| LUKS dentro do CT | independente do host | CT unprivileged não monta dm-crypt; seria preciso no host de qualquer forma |
| AES-256-GCM na aplicação | padrão FIPS | nonce de 96 bits exige contador/cuidado; sem AES-NI garantido no CT; XChaCha20 tem nonce aleatório seguro |
| Cifrar todas as colunas | "tudo cifrado" literal | quebra busca, IPAM, índices, Grafana; não protege mais que disco cifrado + segredos na aplicação |

## Consequências

- Positivas: dump/backup/snapshot vazado não revela anexos, inventário bruto nem segredos; ciphertext adulterado
  ou trocado de coluna falha; rotação de chave sem downtime.
- Negativas: download de anexo não suporta `Range` (decifra em fluxo); payload bruto não é consultável por SQL;
  perder `DATA_ENCRYPTION_KEY` = perder os dados cifrados (guardar em cofre, fora do CT).
- Revisitar: guardar a chave mestra em `systemd-creds`/TPM ou HSM quando o host suportar; remover
  `raw_inventory.payload_legacy` numa migration futura, após todos os ambientes rodarem `app:crypto:rotate`.
