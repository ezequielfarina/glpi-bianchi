# ADR-0016: Infraestrutura física e lógica (DCIM, cabeamento, circuitos e IPAM) no modelo do NetBox

- **Status:** aceito
- **Data:** 2026-11-27
- **Decisores:** Ezequiel Farina

## Contexto

O inventário do GLPI Agent diz **o que** existe (hardware, software, NICs), mas não **onde** está nem **como** está ligado. A TI precisa:

- organizar por filial, prédio, sala e rack, com a posição em U de cada equipamento;
- guardar fotos de racks, equipamentos e locais;
- separar faixas de IP (prefixos, ranges, VLANs) e saber qual IP está em qual porta de qual equipamento;
- ter **rastreamento total do cabo**: da entrada da operadora (DIO/ONT) ao firewall, ao switch, ao patch panel, à tomada na parede e à estação do funcionário.

O [NetBox](https://github.com/netbox-community/netbox) (Apache-2.0, compatível com a GPLv3) é a referência de mercado para isso. A versão analisada é a 4.7.6 (`ezequielfarina/netbox`, commit `04bdbf4`), em especial:

- `dcim/models/{sites,racks,devices,device_components,cables}.py`
- `ipam/models/{ip,vlans,vrfs}.py`
- `circuits/models/circuits.py`

O NetBox é Django e cobre centenas de entidades. Rodá-lo à parte criaria dois cadastros de filial, de ativo e de usuário.

## Decisão

Reimplementar o **núcleo do modelo do NetBox** dentro do GLPI Bianchi, integrado aos cadastros existentes. Não é uma cópia de código: os nomes, as regras e o algoritmo de trace seguem o NetBox, e cada classe portada cita o arquivo de origem no docblock.

### 1. Hierarquia física

| NetBox | GLPI Bianchi |
|---|---|
| Site | **`branch`** (matriz/filial, ADR-0010), sem tabela nova |
| Location | **`location`** existente (árvore `ltree`, ADR-0004), ganha `branch_id`, `kind` (`building`, `floor`, `room`, `datacenter`, `workstation`, `other`) e `description` |
| Region/SiteGroup/Tenant | fora do escopo |

O nó-filho herda a filial do pai (regra no serviço). Os computadores do agente já têm `asset.location_id`, então a "mesa do funcionário" é um `location` do tipo `workstation`.

### 2. Racks e equipamentos

- **`dcim_device_role`**: função com cor (Switch, Patch panel, Ponto de rede, DIO, PDU…).
- **`dcim_device_type`**: fabricante (reaproveita `manufacturer`) + modelo, altura em U (múltiplo de 0,5), profundidade total, função padrão.
- **`dcim_port_template`**: o modelo de portas do tipo. Ao criar um equipamento, as portas e os mapeamentos frente↔traseira são instanciados a partir dele, como os *component templates* do NetBox.
- **`dcim_rack`**: filial, local, altura (U), largura (10/19/21/23"), primeira U, numeração descendente, status.
- **`dcim_device`**:
  - posição no rack (U, face frontal ou traseira), status, número de série, patrimônio;
  - **`asset_id` opcional e único**: o vínculo com o ativo do inventário. Switch, patch panel e tomada não precisam ser ativo; a estação de trabalho aponta para o computador do agente;
  - `primary_ip_id`.
- Sobreposição de U é validada no serviço com `SELECT … FOR UPDATE` no rack. A regra de ocupação da face segue `Rack.get_available_units`: equipamento de profundidade total ocupa as duas faces.

### 3. Portas: uma tabela, sete tipos

O NetBox tem uma tabela por componente (Interface, FrontPort, RearPort, ConsolePort, ConsoleServerPort, PowerPort, PowerOutlet). Aqui é **uma tabela `dcim_port` com `kind`**: `interface`, `front`, `rear`, `console`, `console_server`, `power_port`, `power_outlet`.

- Colunas específicas, todas nulas quando não se aplicam:
  - `mac_address`, `mode` e `untagged_vlan_id` para interface, mais `dcim_port_vlan` para as VLANs *tagged*;
  - `positions` para porta traseira;
  - `maximum_draw`/`allocated_draw` para entrada de energia.
- **`dcim_port_mapping`** (NetBox ≥ 4.5, `PortMapping`): porta frontal + posição ↔ porta traseira + posição. Assim, uma porta traseira com N posições (bandeja de DIO, cabo tronco MPO) carrega N circuitos.
- Motivo: um único alvo de FK para cabos, trace e IPAM, sem `GenericForeignKey`.
- **Energia e console** seguem o mesmo modelo:
  - quadro de energia é um equipamento com tomadas (disjuntores);
  - PDU tem entrada (`power_port`) e tomadas (`power_outlet`);
  - o trace de energia mostra disjuntor → PDU → fonte do servidor.
  - Não foram portados PowerPanel nem PowerFeed: o quadro é modelado como equipamento.

### 4. Cabos

- **`dcim_cable`**: etiqueta, tipo (CAT5e…CAT8, fibra OM3/OM4/OS2, DAC, coaxial, energia, USB), status (`connected`, `planned`, `decommissioning`), cor e comprimento com unidade.
- **`dcim_cable_termination`**:
  - ponta `A`/`B`, com FK **real** para `dcim_port` **ou** `circuit_termination` (CHECK `num_nonnulls(...) = 1`);
  - `UNIQUE` em cada FK: uma porta tem no máximo um cabo;
  - uma ponta pode ter várias terminações do mesmo equipamento (*breakout*).
- Validações portadas de `Cable.clean()`:
  - as duas pontas precisam ter terminação;
  - todas as terminações de uma ponta são do mesmo tipo e do mesmo equipamento;
  - combinações compatíveis (`COMPATIBLE_TERMINATION_TYPES`): interface↔interface/front/rear/circuito, console↔console_server/front/rear, power_port↔power_outlet, e assim por diante;
  - porta virtual/LAG não recebe cabo.

### 5. Trace ponta a ponta

`CableTracer` porta `CablePath.from_origin()`:

- segue cabo → porta frontal → mapeamento → porta traseira → cabo → … com **pilha de posições**, então um tronco de 12 fibras devolve cada fibra na posição certa do outro lado;
- atravessa circuitos de operadora (A↔Z) e para em `provider_network`;
- marca o caminho como `complete`, `active` (todos os cabos `connected`) e `split` (bifurcação sem contexto de posição);
- a partir de uma porta de passagem (patch panel, tomada), traça **nos dois sentidos** e junta os trechos. Clicar na tomada da mesa mostra a cadeia inteira, da operadora até a estação.

Diferença em relação ao NetBox: o caminho é **calculado sob demanda**, não materializado em `CablePath`. Em escala de filial (centenas de portas) custa poucos milissegundos e nunca fica desatualizado, sem a lógica de retrace por sinais do NetBox. Se um dia pesar, a materialização entra como cache (tabela + invalidação pelo componente conexo), sem mudar a API.

### 6. Circuitos

- **Operadora = `supplier`** (fornecedor, ADR-0009) e contrato opcional (`contract_id`). Não há tabela Provider: o contato do NOC e o contrato já existem no fornecedor.
- **`circuit_type`**, **`circuit`** (designação/CID único por operadora, banda contratada, datas de ativação e desativação) e **`circuit_termination`** (lado A/Z, filial e local, velocidade, `xconnect_id`, informação de patch panel, ou `provider_network` quando o lado Z é a nuvem da operadora).

### 7. IPAM

- ~~**`ipam_vrf`** (RD, `enforce_unique`).~~ Removida no [ADR-0021](0021-remover-vrf.md): ambiente só com tabela global + VLANs.
- **`ipam_prefix`**:
  - coluna `CIDR` com índice GiST `inet_ops`;
  - hierarquia **calculada** por `<<` (sem `parent_id`, como no NetBox);
  - status `container`/`active`/`reserved`/`deprecated`, `is_pool`, `mark_utilized`, VLAN, filial e local;
  - utilização igual a `Prefix.get_utilization()`: o contêiner conta os prefixos-filho; os demais contam IPs + faixas.
- **`ipam_ip_range`**: início e fim `INET`, CHECK de mesma família e máscara, e início ≤ fim.
- **`ipam_ip_address`**:
  - `INET` com máscara, índice GiST;
  - status (`active`, `reserved`, `deprecated`, `dhcp`) e papel (`gateway`, `vip`, `vrrp`…), DNS;
  - **`port_id`**: o IP fica na interface do equipamento.
  - Unicidade por VRF quando `enforce_unique` (o padrão do NetBox `ENFORCE_GLOBAL_UNIQUE` também vale para a tabela global): validada no serviço sob `pg_advisory_xact_lock`. Um índice de expressão (`host(address)`) não é representável no mapeamento do Doctrine.
- **Próximo IP livre** (`get_first_available_ip`): percorre os IPs e faixas ocupados em ordem binária (IPv4 e IPv6, sem GMP) e pula rede e broadcast no IPv4 (exceto /31 e /32).
- **Prefixos disponíveis** (`get_available_prefixes`): lacunas entre os filhos decompostas em blocos CIDR mínimos, só IPv4.
- **`ipam_vlan_group`** (faixa de VID) e **`ipam_vlan`** (VID único no grupo).

### 8. Fotos, auditoria e API

- Fotos e documentos usam o módulo `attachment` com os novos donos `location`, `rack`, `device`, `device_type`, `circuit` e `cable`.
- Toda escrita passa pela trilha de auditoria (ADR-0015, rotas `ui_dcim_*`, `ui_ipam_*`, `ui_circuit_*`).
- A API segue o restante da UI (`/api/ui/dcim/*`, `/api/ui/ipam/*`, `/api/ui/circuits/*`): leitura para qualquer perfil, escrita para técnico/admin.

### 9. Cadastros iniciais (migration)

- **Funções:** Switch, Roteador, Firewall, Servidor, Patch panel, Ponto de rede, DIO, PDU, Quadro de energia, Nobreak, Access point, Modem/ONT, Estação de trabalho, Impressora, Organizador.
- **Tipos de circuito.**
- **Tipos genéricos já com as portas:** patch panel 24/48, ponto de rede simples/duplo, DIO 12/24 LC com bandeja de N posições, switch 24/48 + SFP+, PDU 8 tomadas, quadro de 12 disjuntores, estação de trabalho, access point, organizador.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Rodar o NetBox ao lado (outro CT) e integrar por API | produto completo, plugins | Python/Django + Redis + segundo banco; filial, ativo e usuário duplicados; outro login; integração bidirecional frágil |
| Portar todas as entidades do NetBox (Region, Tenant, Module, VirtualChassis, Wireless, VPN…) | paridade | meses de trabalho; a maior parte não se aplica a uma rede de filiais |
| Uma tabela por componente (como o NetBox) | fidelidade ao original | 7 tabelas com as mesmas colunas, FK genérica no cabo, trace e UI com 7 ramos |
| `CablePath` materializado | listagens sem custo de trace | invalidação complexa (sinais do NetBox têm anos de correções); risco de caminho velho |
| Hierarquia de prefixos com `parent_id` | consulta simples | quebra ao inserir um prefixo intermediário; o NetBox também calcula por contenção |

## Consequências

- **Positivas:**
  - uma única fonte de verdade para ativo, local, rack, porta, cabo e IP;
  - o trace mostra o caminho completo da operadora até a mesa;
  - o computador do agente ganha "onde está ligado".
- **Negativas e dívidas assumidas:**
  - Ficam para fases futuras:
    - módulos e baias;
    - virtual chassis/stack;
    - wireless e VPN;
    - virtualização;
    - ASN/FHRP.
  - Não há descoberta automática (LLDP/CDP, ARP, scanner → IPAM): o cadastro é **manual** por decisão desta fase.
  - O trace sob demanda faz consultas por salto. A tela do equipamento calcula o destino de cada porta conectada (dezenas de traces).
- **Revisitar:**
  - se um equipamento com mais de 200 portas conectadas ficar lento, materializar o caminho;
  - quando houver pedido, sugestões automáticas a partir de LLDP/ARP e importação CSV/NetBox.
