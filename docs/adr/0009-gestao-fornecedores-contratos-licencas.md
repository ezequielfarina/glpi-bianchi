# ADR-0009: Gestão — fornecedores, contratos, licenças, anexos e patrimônio

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

Além do inventário automático, é preciso gerir: licenças em vários modelos (Microsoft 365 por
usuário, OEM/Retail por máquina, volume MAK/KMS), fornecedores e contatos, contratos com valores
e vigência, documentos anexos, e ativos sem agente (impressoras, monitores, coletores, tablets,
telefones) com foto e número de patrimônio.

## Decisão

**Modelo** (migration `Version20261126170000`):
- `license_type` (com `assignment` padrão: device/user/volume) e `license_category` editáveis;
  `license` (assentos, valor unitário, compra/vencimento, `software_match`, chave **cifrada**);
  `license_assignment` com exatamente um alvo — ativo **ou** usuário (CHECK no banco).
- `supplier` (CNPJ único) + `contact`; `contract_type` editável; `contract` (vigência,
  renovação, aviso, periodicidade, valor) + `contract_asset`.
- `attachment` polimórfico (`owner_type`, `owner_id`), binário em disco.
- Patrimônio: `asset.inventory_number` único + sequência `asset_inventory_seq`; prefixo em
  `app_setting` (`asset.inventory_prefix`, padrão `BIA-`). Gerado para ativos manuais **e** do agente.

**Escrita via DBAL nos `Ui\Write\*Writer`** (SQL explícito, `RETURNING`), não via ORM:
regras de validação e histórico explícitas, e testáveis contra PostgreSQL real sem kernel.
As entidades Doctrine continuam sendo a fonte do schema (`schema:validate` no CI).

**Segurança:**
- chave de licença: libsodium secretbox, chave `DATA_ENCRYPTION_KEY` (gerada pelo install.sh,
  nunca regenerada); revelação registrada em log;
- anexos: MIME detectado pelo conteúdo, lista branca, 20 MB, nome em disco = UUID fora do
  webroot, download autenticado com `nosniff` + CSP `sandbox`.

**Ativo dinâmico editado à mão** trava automaticamente nome/serial/fabricante/modelo
(`locked_field`), como o GLPI faz — senão o próximo inventário desfaria a correção.

## Consequências

- Backup passa a incluir `/var/lib/glpi-bianchi/files` e o `.env.local` (chave de criptografia).
- `software_match` é heurístico (ILIKE no nome do software); refinamento por dicionário na fase 3.
- Autor das alterações é "painel" até existir login individual (fase 2: LDAP/OIDC).
