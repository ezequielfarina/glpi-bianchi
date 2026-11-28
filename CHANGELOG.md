# Changelog

Formato: [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) · Versionamento: [SemVer](https://semver.org/lang/pt-BR/)

## [Unreleased]

### Adicionado — VPN IPsec (ADR-0022)
- **Importar túneis do FortiGate** colando `show [full-configuration] vpn ipsec …` ou o backup `.conf`: fase 1 (interface e policy-based),
  fases 2 com seletores resolvidos (objetos e grupos de endereço), IP local, IP do túnel, rotas estáticas pelo túnel, zona SD-WAN e VDOMs.
- **PSK nunca guardada**: `ENC …`, psksecret, senhas e chaves privadas descartadas na leitura; texto colado não é gravado nem auditado.
- **Pareamento automático** dos dois lados pelos IPs e **diagnóstico**: IKE, autenticação, proposta/DH com interseção, PFS, keylife e
  seletores da fase 2 espelhados; fase 1 lado a lado.
- Redes do túnel ligadas aos prefixos do IPAM; documentação (outro lado, fornecedor, circuito, onde está a PSK); comandos de troubleshooting.
- Reimportação idempotente: túnel que saiu da config fica marcado, documentação preservada.

### Removido
- **VRF** do IPAM (ADR-0021): campo, filtros, aba e API `/api/ui/ipam/vrfs`. Prefixos, faixas e IPs passam a ser únicos globalmente; a migration aborta se algum registro estiver em VRF.

### Adicionado — Wi-Fi / UniFi: identidade de MAC (ADR-0020)
- Cadastro do controller UniFi da VM (admin local com cookie+CSRF, API key no UniFi OS/OS Server ou proxy da nuvem), com
  **pin do certificado** autoassinado ("Ler certificado") e credencial cifrada.
- Sincroniza **sites** e **redes Wi-Fi** com a lista atual do filtro de MAC; vincula site à filial.
- **Cadastro de MAC** com pessoa (nome, matrícula, setor, contato), aparelho (tipo, marca, modelo, hostname, patrimônio), validade e
  redes: aplica no UniFi na hora (PUT parcial só do filtro — a senha do Wi-Fi nunca é regravada) ou na próxima sincronização.
- Preserva o que já está no UniFi: MACs sem cadastro aparecem em "Sem identificação" para identificar; nada é apagado sem identidade.
- Revogação, vencimento automático (visitantes/terceiros), conferência após gravar, estados pendente/erro com nova tentativa,
  apelido "Pessoa — Aparelho" no cliente do UniFi, aviso de filtro desligado e de MAC aleatório, exportação CSV.
- **Trocar senha/API key** do controller: a credencial nova é testada no UniFi antes de gravar (erro de digitação não derruba a integração).
- **Sincronizar site**: relê só as redes de um site (árvore e cabeçalho da rede).
- Árvore e seletor de redes com **sites recolhíveis**, busca e "Só com filtro"; aviso de controller com falha e de lista lida há mais de 30 min.
- Validações do cadastro: MAC em tempo real, **MAC aleatório exige confirmação**, ao menos uma rede, limite de 512 por rede, formato de telefone/matrícula/hostname,
  validade até 5 anos, limpeza de caracteres invisíveis colados de planilha.
- "Identificar" liga o MAC a todas as redes do controller onde ele já está e **reativa** cadastro revogado (antes a aplicação seguinte o retirava do UniFi).
- Segurança/robustez: controller só na rede interna (anti-SSRF), resposta limitada, uma sessão por requisição (sem bloqueio por excesso de logins),
  nova tentativa em falha passageira, trava de sincronização por controller, recusa de deixar a lista de permissão vazia com o filtro ligado,
  e-mail/telefone mascarados para o perfil Leitura.
- Cadastro e busca por MAC mostram **em qual site › rede** o MAC já está liberado no UniFi sem identificação, com "Marcar estas redes" / "Identificar".
- Timer `bianchi-wifi-sync` (10 min) e comando `app:wifi:sync`; todas as ações na auditoria (módulo Wi-Fi).

### Corrigido — scanner de rede
- Varredura morta por reinício do worker (deploy, `rotate-secrets.sh`, reboot) aparecia como "nmap terminou com código 15" com o stderr bruto: agora é **interrompida**, mantém os dispositivos já encontrados e, se for agendada, roda de novo em 5 minutos (sem alerta de falha).
- Varredura órfã (worker morto sem registrar o fim) ficava "em andamento" para sempre e bloqueava o agendamento: o tick encerra depois do tempo máximo do perfil.
- Worker do scanner com `CAP_NET_BIND_SERVICE`: a sonda IKE do `-sV` (VPN, UDP 500) usa porta de origem 500 e falhava com "Bind to 0.0.0.0:500 failed: Permission denied".
- Mensagem de erro do nmap resumida (linhas repetidas viram "(×N)").
- `install.sh` avisa quando o reinício vai interromper uma varredura em andamento.

### Segurança (auditoria 2026-11 — docs/security/auditoria-2026-11.md)
- **Crítico:** com `APP_ENV=dev`, `/_profiler` expunha sem login os segredos (`API_TOKEN`, `DATA_ENCRYPTION_KEY`, senha do banco) e cookies de sessão. Agora `/_profiler`, `/_wdt` e `/_error` só respondem a loopback (aplicação + nginx) e o painel web roda sempre com `APP_DEBUG=0`. **Gire os segredos:** `sudo bash deploy/lxc/rotate-secrets.sh`.
- Endpoint do GLPI Agent: só redes permitidas (`AGENT_ALLOWED_NETWORKS`), credencial Basic opcional (`AGENT_BASIC_AUTH`) e limite por IP — impede inventário falso sobrescrevendo ativos.
- **Criptografia em repouso (ADR-0019):** XChaCha20-Poly1305 com contexto e rotação de chave (`app:crypto:rotate`); anexos cifrados em disco (chave por arquivo); inventário bruto cifrado; backup diário cifrado com `age` (`deploy/lxc/backup.sh`); guia de ZFS cifrado no host.
- XSS armazenado por link `javascript:` (site do fornecedor) bloqueado no backend e neutralizado no frontend.
- Bloqueio de login por login+IP (5) e global (25): ninguém trava a conta do administrador de outra máquina; troca de senha e desligar 2FA com o mesmo limite.
- Respostas do painel com `Cache-Control: no-store` (nada fica no cache em disco do navegador); log do PostgreSQL sem valores de parâmetros.
- Erros 500 → 400/422: senha vazia, número gigante na URL, array no lugar de texto (inclusive no endpoint público do agente).

### Corrigido
- Mapa/caminho: cache de vizinhos do `TopologyService` não sobrevive mais entre chamadas (resultado velho em processo longo).
- Anexar arquivo em **filial** ou **nota fiscal** dava erro 500 (tabela fora da lista de validação).
- Índice redundante `invoice(supplier_id)` removido (já coberto por `uniq_invoice_number`; `schema:validate` acusava diferença).
- `install.sh`: `net.ipv4.ping_group_range` limitado ao `gid_map` do CT unprivileged (o ping do Diagnóstico ficava sem permissão); PostgreSQL só reinicia quando um parâmetro exige (`pending_restart`), senão `reload` — o restart incondicional derrubava a varredura em andamento.
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
- **Listas editáveis nos campos (ADR-0018)**: sistema operacional, função, plataforma e grupo do cluster e tipo de circuito abrem uma lista **logo abaixo do campo**, no visual do projeto, com busca e teclado; dá para **cadastrar, renomear e excluir** itens ali mesmo (exclusão só sem uso; renomear SO atualiza as VMs). A função também define **cor e desenho** no mapa.
- **Cabos do mapa como fita de LED**: curvas maleáveis com caimento e profundidade (tubo 3D com brilho), LEDs na cor real do cabo e pulsos de tráfego nos dois sentidos; planejado fica apagado; respeita *reduzir movimento*.
- **Mapa da rede com desenho dos equipamentos**: switch com portas e LEDs, firewall, servidor/host com baias, patch panel, DIO, PDU com tomadas, quadro, ONT, AP, computador, VM, cluster, circuito e nuvem da operadora — caixas 3D em ¾ no modo 3D e o mesmo desenho no 2D e na legenda.
- **Virtualização e mapa da rede (ADR-0017)** — menu *Virtualização* e *Infraestrutura → Mapa da rede*:
  - **Clusters** Proxmox VE, ESXi, Hyper-V, XCP-ng, KVM, AHV com hosts físicos (equipamentos do rack) e anéis de consumo × capacidade; capacidade do host lida do inventário do GLPI Agent quando não informada.
  - **VMs** com vCPU, RAM, discos virtuais, interfaces (bridge, MAC, VLAN), IPs e IP primário, vínculo com o ativo, capturas de tela e documentos.
  - **Caminho até a internet**, passo a passo e reverso: VM → virtualizador → host → cabos/patch panels → switches → firewall → ONT → circuito da operadora, com o que falta cadastrar quando não fecha.
  - **Mapa da rede** 3D (WebGL) ou 2D, lógico ou físico, cores da função e do cabo, tráfego animado, busca e painel de detalhes ao clicar.
  - **Ajuda em todos os campos**: ícone **!** com explicação e exemplo (passar o mouse ou tocar).
- **Infraestrutura física e lógica no modelo do NetBox (ADR-0016)** — menu *Infraestrutura* e *IPAM*:
  - **Locais** por filial em árvore (prédio, andar, sala/CPD, posto de trabalho); **racks** com elevação frontal/traseira (clique na U vazia para instalar), ocupação e fotos.
  - **Tipos de equipamento** com modelo de portas (lote `Gi1/0/[1-48]`, par frontal/traseira para patch panel/tomada) e tipos genéricos prontos (patch panel, tomada, DIO com bandeja multiposição, switch, PDU, quadro, estação, AP).
  - **Equipamentos** com interfaces, portas de patch, console e energia; vínculo opcional com o ativo do inventário (copia as NICs com MAC); IP primário; resumo de carga da PDU.
  - **Cabos** com etiqueta, tipo, cor e comprimento e as regras do NetBox (uma porta = um cabo, tipos compatíveis); **rastreamento ponta a ponta** desenhado como no NetBox, com fibra em tronco (posição certa no DIO remoto), circuito de operadora até a rede dela, e trace nos dois sentidos a partir da tomada/patch panel. Na porta: *Ligado em* e *Destino final*; no ativo: onde está e em qual porta de switch chega.
  - **Circuitos** (operadora = fornecedor, contrato) com lados A/Z.
  - **IPAM**: VRFs, prefixos em árvore com utilização, blocos livres e **próximo IP livre** (pula faixas DHCP), mapa de IPs livres/usados da sub-rede, faixas, IPs por interface, grupos de VLAN e VLANs (acesso/tronco nas portas).
  - **Fotos** (câmera do celular) e documentos em locais, racks, equipamentos, tipos, circuitos e cabos. Auditoria de todas as escritas.
- Menu lateral em **seções recolhíveis** (Estoque, Gestão, Inventário automático, Ferramentas, Sistema): estado salvo no navegador, a seção da página atual abre sozinha, seção fechada mostra o total de alertas/itens a comprar; a lista rola e o rodapé (tema/sair) fica fixo — os últimos itens não ficam mais cortados.
- Saída para filial com **prévia da etiqueta ao lado** (exatamente o que sai na impressora) e troca rápida do IP/porta/linguagem da impressora pelo Administrador; cabeçalho da etiqueta (REMETENTE/DESTINATÁRIO) maior, como no modelo.
- **Etiqueta impressa direto na Argox pela rede (porta 9100):** ao registrar a saída, uma etiqueta por volume sai automaticamente, desenhada no servidor (layout do modelo, acentos e logo) e enviada em PPLA, PPLB ou PPLZ/ZPL (a ArgoxBancada usa PPLA — sem trocar a emulação). Configuração e etiqueta de teste em *Configurações*; prévia exata na tela da etiqueta; falha de conexão mostra o erro e as alternativas (.odt e navegador). `install.sh` instala `php-gd` e `fonts-liberation2`.
- Etiqueta de envio em **.odt a partir do modelo Entrega_TI** para imprimir pelo LibreOffice na Argox (pelo navegador o Chrome envia a etiqueta como paisagem e o driver em retrato desloca a impressão).
- **Estoque separado dos ativos de TI:** equipamento guardado no estoque não aparece mais em Ativos nem nos totais da visão geral; entra na lista quando sai para a filial. Filtro *Em uso / + estoque / só guardados*, aviso quando a busca só encontra guardados e origem (item, entrada, NF) no detalhe do ativo.
- **Auditoria de ações (ADR-0015):** tudo que é feito pela interface fica registrado (cadastros, alterações, exclusões, movimentações de estoque, notas, ferramentas, monitoramento, usuários, configurações, exibição de chave de licença) com usuário, IP, objeto (lido antes da exclusão) e o que foi enviado — sem segredos. Somente inclusão (o banco bloqueia alteração e exclusão). Tela *Auditoria* com abas *Ações no sistema* e *Acessos e contas*.
- **Estoque central de TI e notas fiscais (ADR-0014):**
  - Itens com estoque mínimo e lista "a comprar"; contador no menu (alerta só no sistema).
  - Nota fiscal simplificada (número, série, fornecedor, emissão, itens com valor unitário e total). Registrar a nota dá entrada no estoque; a diferença entre o total e a soma dos itens é exibida.
  - Itens com patrimônio: cada unidade vira um ativo "Em estoque" com patrimônio automático e série opcional; a saída entrega o ativo à filial.
  - Situação da nota: registrada → enviada para lançamento (com data e autor), inclusive em lote. O lançamento é feito por outro setor e não é acompanhado no sistema.
  - Saída para filial, entrada manual (saldo inicial, devolução) e ajuste com motivo; histórico de movimentações e consumo por filial.
  - Anexo de DANFE/XML na nota.
  - Etiqueta de envio gerada na saída para filial (modelo Entrega_TI: destinatário com código da filial, setor A/C, logo, volumes), no tamanho do papel do driver da impressora térmica (Argox, padrão 102,4 × 74,9 mm); reimpressão pelas movimentações.
- **Monitoramento de rede (ADR-0013):**
  - Varreduras agendadas: horário fixo nos dias escolhidos ou a cada N horas, via timer systemd `bianchi-scan-scheduler`.
  - Alertas por comparação com a execução anterior: dispositivo novo sem cadastro, MAC trocado num IP (crítico no gateway), portas novas (de risco em destaque), ativo que sumiu, varredura que falhou.
  - Alertas com deduplicação, reconhecimento individual ou em lote e badge com o número de alertas abertos no menu.
  - Notificações para Microsoft Teams (Workflows), Telegram, Slack/Mattermost/Google Chat ou webhook JSON. Destino cifrado, teste ao salvar, só Administrador.
- **Conflitos de IP/MAC:**
  - Conflito real via arp-scan (dois MACs no mesmo IP), com alerta crítico.
  - IP em uso por outro equipamento (varredura × inventário).
  - MAC repetido em vários ativos (VM clonada, cadastro duplicado, dock USB).
  - IP repetido no inventário.
  - Ignora adaptadores virtuais de VPN e hipervisor (FortiClient, AnyConnect, Npcap, VMnet).
- **Diagnóstico e consultas** (a partir do servidor):
  - Ping com jitter e TTL.
  - Traceroute com estatística por salto (MTR).
  - Teste de portas TCP com presets (AD, FortiGate, MikroTik, Proxmox…), certificado TLS (validade, SAN, cadeia) e banner.
  - DNS comparando servidores (split-horizon).
  - ASN/prefixo BGP (Team Cymru via DNS) e whois por RDAP.
  - Fabricante de MAC em lote (MA-L/M/S), com onde o MAC aparece no inventário e nas varreduras.
  - Atalho "Diagnosticar" no detalhe do ativo.
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
