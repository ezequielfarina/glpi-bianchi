# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · Versionamento: [SemVer](https://semver.org/lang/pt-BR/)

## [Unreleased]

### Corrigido
- Ativo excluído com o GLPI Agent ainda instalado voltava **duplicado** (novo patrimônio, sem
  histórico): agora o mesmo ativo é restaurado da lixeira (por UUID de hardware, serial ou vínculo do agente).
- F5 no detalhe do ativo dava 404 do nginx: o build do Vite usava `/app/assets/`, a mesma rota da tela.
  Arquivos do build agora em `/app/_static/`.
- Após "Forçar inventário" a tela recarrega em 15/30/60/90/120 s (antes: uma vez, aos 15 s) e sem piscar.
- MAC das interfaces lido de `mac` (formato JSON), não `macaddr`.
- Inventário parcial não apaga mais atributos ausentes do payload (ex.: `last_logged_user`).
- `install.sh`: `composer update` automático em dev com lock dessincronizado; `reset-failed` dos workers.
- Escritas sujeitas a constraint isoladas em savepoint (evita `25P02` na transação externa).
- Migration de seed compatível com DBAL 4 (`ParameterType`); `DEFAULT_URI`; `.env.test.local` e `make`/`jq` no install.

### Adicionado
- **Menu Ferramentas (ADR-0012):**
  - **Scanner de rede:** nmap em worker dedicado (`bianchi-scan-worker`, `CAP_NET_RAW` sem root).
    - Perfis Descoberta/Padrão/Completo e progresso ao vivo.
    - Mostra IP, MAC, fabricante, NetBIOS/DNS, SO, portas e serviços, títulos web, certificados TLS, SMB/RDP.
    - MAC de outras VLANs via tabela ARP do gateway (SNMP v2c, community não persistida).
    - Classifica o tipo de dispositivo, cruza com o inventário (MAC → IP → nome) e detecta a filial.
    - "Cadastrar" abre o formulário de ativo já preenchido.
    - Exporta CSV e guarda histórico.
    - Restrito a redes privadas e ao perfil Técnico/Administrador.
  - **Calculadora de sub-rede:** rede, broadcast, faixa utilizável, máscara/wildcard e tipo de IP com RFC.
    - Binário com bits de rede/host, hexadecimal e DNS reverso.
    - Divisão em sub-redes, redes vizinhas e "N hosts → prefixo".
    - Configuração pronta para FortiOS, RouterOS, Linux, PowerShell e Cisco.
- **Login individual (ADR-0011):** usuário e senha com **Argon2id**, sessão em cookie HttpOnly/SameSite=Strict
  (só o SHA-256 do token no banco), **2FA TOTP** com QR code e anti-replay, perfis Administrador/Técnico/Leitura
  aplicados no servidor, bloqueio progressivo (5 falhas por login / 30 por IP), proteção CSRF, troca obrigatória
  de senha temporária, "Minha conta" (senha, 2FA, sessões), painel de **usuários** e **auditoria de acesso**.
  Histórico dos ativos passa a registrar o login de quem alterou.
- **HTTPS no CT:** TLS 1.2/1.3 com certificado auto-assinado gerado na instalação (substituível), CSP sem inline;
  porta 80 só para o GLPI Agent. `install.sh` cria o `admin` inicial com senha temporária.
- Comandos `app:user:create` e `app:user:recover` (senha temporária, 2FA, desbloqueio, reativação).
- **Matriz e filiais (ADR-0010):** cadastro com CNPJ, endereço (busca por CEP), localização no mapa
  (OpenStreetMap: clicar/arrastar, localizar pelo endereço ou colar link do Google Maps) e redes CIDR;
  filial no ativo (formulário, detalhe, filtro e coluna na lista, mover em lote); computadores do
  agente sem filial são vinculados pela rede do IP (prefixo mais específico vence; manual nunca é sobrescrito).
- Tipos/categorias de licença e tipos de contrato editáveis **dentro dos formulários** ("Gerenciar"),
  com o item novo já selecionado; diálogos empilháveis (ESC fecha só o do topo).
- `composer.lock`/`symfony.lock` versionados (deploy reprodutível) e configs geradas pelo Flex.
- **Gestão (ADR-0009):** fornecedores e contatos; contratos (vigência, renovação, valor, ativos);
  licenças (tipos/categorias editáveis, por máquina/usuário/volume, chave cifrada, conformidade
  com software detectado); anexos (fotos e documentos); patrimônio sequencial automático.
- Cadastro e edição manual de ativos (impressoras, monitores, coletores de dados, tablets, telefones…),
  com histórico e trava automática de campos editados em ativos do agente.
- Resultado de cada inventário (novo ativo / atualizado + nº de alterações / sem alterações).
- Forçar inventário pelo painel (agente e ativo) via `/now` do GLPI Agent, com diagnóstico (ADR-0008).
- Tela Configurações: frequência de inventário (`expiration`) e porta HTTP do agente.
- Interface web Vue 3 em `/app/`: visão geral (KPIs, inventários/hora, SOs), ativos (busca por
  nome/serial/patrimônio/UUID/MAC/IP), detalhe (hardware, software, rede, histórico, payload),
  agentes e inventários recebidos; tema escuro/claro; responsiva.
- Endpoints `/api/ui/*` com read models SQL dedicados (`src/Ui/`).
- Estrutura inicial do projeto (Symfony 7.3, API Platform 4, Doctrine ORM 3, PostgreSQL 17).
- Endpoint compatível com o GLPI Agent: `/`, `/front/inventory.php`, `/inventory`
  (PROLOG XML, CONTACT, GET_PARAMS, INVENTORY; JSON e XML legado; zlib/gzip/deflate).
- Validação do inventário contra o JSON Schema oficial (`glpi-project/inventory_format`).
- Fila assíncrona de processamento (Messenger + Valkey), retry com backoff e fila de falhas.
- Importador `Computer`: hardware/BIOS, sistema operacional, CPU, memória, discos/unidades
  ópticas, software e interfaces de rede (IPv4/IPv6).
- Inventário parcial, campos travados (`locked_field`) e histórico por campo (`asset_history`).
- Hierarquias de organização e localização em `ltree`, mantidas por trigger (com bloqueio de ciclos).
- API REST com OpenAPI em `/api/docs`, autenticação por token Bearer.
- Provisionamento de CT LXC Proxmox (Debian 13) via `deploy/lxc/install.sh`, workers em systemd.
- `Blacklist` de seriais/UUIDs/modelos/fabricantes placeholder (portada do GLPI).
- Comando `app:inventory:reprocess` (id, `--latest`, `--failed`, `--device`).
- Documentação: ADRs 0001–0006, modelo de dados, protocolo do agente, importação, deploy e API.
