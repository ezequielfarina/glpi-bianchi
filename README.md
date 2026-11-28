# GLPI Bianchi — Inventário de Ativos de TI

Sistema de **gerenciamento de inventário** de TI, reescrito a partir dos conceitos do
[GLPI](https://github.com/glpi-project/glpi) **sem o módulo de suporte** (helpdesk, chamados,
problemas, mudanças, SLA, base de conhecimento e projetos).

É **compatível com o GLPI Agent**: os agentes já instalados no parque só precisam apontar
`server` para este servidor. Também é possível mandar para o GLPI e para o Bianchi ao mesmo tempo.

| | |
|---|---|
| **Stack** | PHP 8.3+ · Symfony 7.3 · API Platform 4 · Doctrine ORM 3 · PostgreSQL 17 · Valkey · Messenger |
| **Deploy** | CT LXC no Proxmox (Debian 13), serviços nativos com systemd — sem Docker |
| **Licença** | GPL-3.0-or-later |
| **Status** | MVP (fase 1): ingestão do agente + ativos `Computer` + API REST + interface web |

---

## Sumário

- [Arquitetura](#arquitetura)
- [Início rápido (CT Proxmox)](#início-rápido-ct-proxmox)
- [Apontando o GLPI Agent](#apontando-o-glpi-agent)
- [Estrutura do repositório](#estrutura-do-repositório)
- [Comandos do dia a dia](#comandos-do-dia-a-dia)
- [Roadmap](#roadmap)
- [Documentação](#documentação)

## Arquitetura

```
 GLPI Agent ──POST zlib/JSON──► nginx ──► PHP-FPM ──► AgentController
 (Win/Linux/Mac)                                       │  1. decodifica + valida (JSON Schema oficial)
                                                       │  2. grava raw_inventory (JSONB)
                                                       │  3. enfileira ProcessInventory ──► Valkey
                                                       ▼
                                            responde {"status":"ok"} em ms

 bianchi-worker@N (systemd) ◄── Valkey ── messenger:consume inventory
   └─ ProcessInventoryHandler
        ├─ pg_advisory_xact_lock(deviceid)   (serializa o mesmo dispositivo)
        ├─ AssetMatcher  (uuid → serial → agente → nome)
        └─ Seções: Hardware · OS · CPU · Memória · Storage · Software · Rede
             └─ campos travados + histórico por campo (asset_history)

 API REST (/api, Bearer) ─── API Platform ─── PostgreSQL 17 ──► Grafana / Zabbix
```

As decisões estão registradas em [`docs/adr/`](docs/adr/).

## Início rápido (CT Proxmox)

Requisitos: CT **Debian 13 (trixie)** unprivileged com `nesting=1`, 2 vCPU, 4 GB de RAM e 32 GB de disco.

```bash
# dentro do CT, como root
apt update && apt install -y git
git clone https://github.com/ezequielfarina/glpi-bianchi.git /opt/glpi-bianchi
cd /opt/glpi-bianchi
bash deploy/lxc/install.sh --env=dev     # lab: inclui PHPUnit/PHPStan
# bash deploy/lxc/install.sh             # produção
```

O script é idempotente e no final mostra a URL do painel e a **senha temporária do usuário `admin`**
(troca obrigatória no primeiro login). **Interface web:** `https://<ip-do-ct>/app/` — certificado auto-assinado
na primeira instalação (o navegador avisa; troque pelo da CA interna). Passo a passo completo,
TLS e troubleshooting em [`docs/deploy/proxmox-ct.md`](docs/deploy/proxmox-ct.md).

Teste sem agente nenhum:

```bash
make agent-sample                        # CONTACT + INVENTORY de exemplo (zlib)
TOKEN=$(sed -n 's/^API_TOKEN=//p' .env.local)       # token só para integrações (/api), não abre o painel
curl -sk -H "Authorization: Bearer $TOKEN" https://127.0.0.1/api/assets | jq
```

## Apontando o GLPI Agent

```bash
# Linux
glpi-agent --server http://<ip-do-ct>/ --force --debug

# Windows (instalação silenciosa)
msiexec /i GLPI-Agent-1.x-x64.msi /quiet RUNNOW=1 SERVER="http://<ip-do-ct>/"

# Enviar para o GLPI de produção E para o Bianchi (comparação lado a lado)
server = https://glpi.empresa.local/, http://<ip-do-ct>/
```

Rotas aceitas: `/`, `/front/inventory.php` (legado), `/inventory`. Detalhes do protocolo em
[`docs/inventory/protocolo-agente.md`](docs/inventory/protocolo-agente.md).

## Estrutura do repositório

```
├── src/                       # backend — um diretório por módulo (bounded context)
│   ├── Organization/          #   organizações (ltree) e localizações
│   ├── Identity/              #   usuários e grupos
│   ├── Asset/                 #   ativo, tipo, modelo, fabricante, componente, travas, histórico
│   ├── Software/              #   catálogo de software, versões, instalações
│   ├── Network/               #   portas, IPs, VLANs
│   ├── Inventory/             #   protocolo do agente, recepção, fila, importador por seção
│   ├── Settings/              #   configurações editáveis no painel (app_setting)
│   ├── Ui/                    #   read models SQL + endpoints /api/ui/* da interface web
│   └── Shared/                #   tipos Doctrine, segurança, traits
├── migrations/                # banco — migrations Doctrine (SQL explícito)
├── config/                    # Symfony (packages, rotas, serviços)
├── deploy/lxc/                # provisionamento do CT: install.sh, nginx, FPM, PostgreSQL, systemd
├── frontend/                  # interface web — Vue 3 + Vite + TS; build versionado em dist/
├── tests/                     # Unit + Integration (PHPUnit) e fixtures de inventário
├── tools/                     # utilitários (send-inventory.sh)
└── docs/                      # ADRs, banco, protocolo, deploy, API
```

O repositório é dividido em três frentes, cada uma com dono e convenções próprias
descritas em [`CLAUDE.md`](CLAUDE.md): **banco** (`migrations/`, `docs/database/`),
**backend** (`src/`, `config/`, `tests/`) e **frontend** (`frontend/`).

## Comandos do dia a dia

| Comando | O que faz |
|---|---|
| `make help` | lista todos os alvos |
| `make status` / `make logs` | estado dos serviços / logs dos workers |
| `make migrate` / `make diff` | aplica migrations / gera migration a partir das entidades |
| `make test` | cria o banco de teste e roda o PHPUnit |
| `make lint` / `make fix` | php-cs-fixer + PHPStan (nível máximo) + Rector |
| `make ci` | o mesmo pipeline do GitHub Actions, local |
| `make update` | deploy: pull, deps, migrations, cache, reload FPM e workers |
| `make agent-sample` | simula um GLPI Agent enviando inventário |

## Roadmap

**Fase 1 — MVP (atual)**
- [x] Endpoint compatível com o GLPI Agent (PROLOG/CONTACT/INVENTORY, JSON/XML, zlib/gzip/deflate)
- [x] Validação contra o JSON Schema oficial (`glpi-project/inventory_format`)
- [x] Fila assíncrona (Messenger + Valkey) com retry e fila de falhas
- [x] Importador `Computer`: hardware, SO, CPU, memória, discos, software, rede
- [x] Inventário parcial, campos travados e histórico por campo
- [x] API REST + OpenAPI (`/api/docs`) com token
- [x] Provisionamento de CT Proxmox (systemd, sem Docker)
- [x] Interface web (Vue 3): visão geral, ativos, detalhe, agentes, inventários; tema escuro/claro
- [x] Gestão: fornecedores, contratos, licenças, anexos, patrimônio; matriz e filiais com mapa
- [x] Login individual (Argon2id), 2FA TOTP, perfis Administrador/Técnico/Leitura, auditoria de acesso, HTTPS
- [x] Ferramentas: scanner de rede (nmap, SNMP ARP, correlação com inventário) e calculadora de sub-rede
- [x] Monitoramento: varreduras agendadas, alertas de mudança e conflito de IP/MAC, notificações (Teams/Telegram/Slack/webhook), diagnóstico (ping/MTR/portas/TLS) e consultas (DNS/RDAP/ASN/MAC)
- [x] Estoque central de TI e notas fiscais simplificadas (entrada por nota, patrimônio por unidade, saída para filial, mínimo/"a comprar", etiqueta de envio para Argox)
- [x] Auditoria de todas as ações da interface (somente inclusão, sem segredos) + auditoria de acesso
- [x] Infraestrutura no modelo do NetBox: locais por filial, racks com elevação, equipamentos com portas, cabos com **rastreamento ponta a ponta** (operadora → firewall → switch → patch panel → tomada → estação), circuitos, energia/console, IPAM (prefixos, faixas, IPs, VLANs, VRFs) e fotos
- [x] Virtualização (Proxmox, ESXi, Hyper-V…): clusters, hosts, VMs com recursos, caminho reverso VM → operadora e **mapa da rede 3D/2D**
- [ ] Virtualização: importar VMs pela API do Proxmox/vCenter
- [ ] Infraestrutura: sugestões por LLDP/ARP/scanner, importação CSV/NetBox, etiqueta de cabo/tomada na Argox
- [ ] Notificação por e-mail (symfony/mailer + SMTP)
- [ ] Estoque: importação do XML da NF-e, pedido de compra, aviso de mínimo no Teams/Telegram

**Fase 2**
- [ ] Login LDAP/AD e OIDC (Entra ID/Keycloak) com grupos → perfis; permissão por filial
- [ ] Financeiro: Infocom, depreciação, garantia
- [ ] Regras de importação configuráveis e dicionários (fabricante, software)
- [ ] `NetworkEquipment`/`Printer` via SNMP (netdiscovery/netinventory), ESX
- [ ] Licenças de software, volumes/filesystems, monitores e periféricos via inventário
- [ ] Métricas Prometheus, template Zabbix e dashboards Grafana

## Documentação

| Documento | Conteúdo |
|---|---|
| [docs/adr/](docs/adr/) | decisões de arquitetura (por que reescrever, stack, modelo de dados...) |
| [docs/tools/scanner-de-rede.md](docs/tools/scanner-de-rede.md) | scanner de rede (capabilities, SNMP, FortiGate) e calculadora |
| [docs/dcim/infraestrutura.md](docs/dcim/infraestrutura.md) | locais, racks, equipamentos, cabos e rastreamento, circuitos, energia, IPAM — passo a passo e convenções |
| [docs/virtualization/virtualizacao-e-mapa.md](docs/virtualization/virtualizacao-e-mapa.md) | clusters, hosts, VMs, caminho até a internet e mapa da rede |
| [docs/security/auditoria-2026-11.md](docs/security/auditoria-2026-11.md) | auditoria de segurança e testes de invasão (achados, correções, ações) |
| [docs/security/criptografia.md](docs/security/criptografia.md) | criptografia: chaves, rotação, backup cifrado, ZFS no host, agentes com credencial |
| [docs/stock/estoque-e-notas.md](docs/stock/estoque-e-notas.md) | estoque, notas fiscais, saída para filial e etiqueta de envio (impressão na Argox) |
| [docs/audit/auditoria.md](docs/audit/auditoria.md) | auditoria: ações no sistema e acessos, o que é registrado, consultas SQL |
| [docs/database/README.md](docs/database/README.md) | modelo de dados, ER, índices, triggers, manutenção |
| [docs/inventory/protocolo-agente.md](docs/inventory/protocolo-agente.md) | protocolo do GLPI Agent implementado |
| [docs/inventory/forcar-inventario.md](docs/inventory/forcar-inventario.md) | forçar inventário remoto, frequência, firewall e `httpd-trust` |
| [docs/inventory/importacao.md](docs/inventory/importacao.md) | como o inventário vira ativo (matching, seções, travas) |
| [docs/deploy/proxmox-ct.md](docs/deploy/proxmox-ct.md) | CT Proxmox: criação, install.sh, TLS, operação, troubleshooting |
| [docs/api/README.md](docs/api/README.md) | API REST: autenticação, recursos, filtros, exemplos |
| [CONTRIBUTING.md](CONTRIBUTING.md) | fluxo de trabalho, padrões de código, commits |
