# Infraestrutura física e lógica: locais, racks, cabos, circuitos e IPAM

Módulo inspirado no [NetBox](https://github.com/netbox-community/netbox) (versão 4.7.6), reimplementado dentro do GLPI Bianchi (decisão e diferenças em [ADR-0016](../adr/0016-dcim-ipam-cabeamento.md)). Ele mostra, para cada filial:

- **onde** está cada equipamento: prédio, andar, sala/CPD, rack e U, ou a mesa do funcionário;
- **como** está ligado: cabo por cabo, com o **rastreamento** completo desde a entrada da operadora até a tomada na mesa;
- **qual IP** está em qual interface, em qual faixa e em qual VLAN.

Os cadastros são manuais nesta fase. O inventário do GLPI Agent continua sendo a fonte do hardware e do software: o equipamento físico pode apontar para o ativo do agente.

## Menu

| Seção | Tela | Para quê |
|---|---|---|
| Infraestrutura | **Locais** | árvore por filial: prédio → andar → sala/CPD → posto de trabalho (mesa) |
| | **Racks** | elevação frontal e traseira, ocupação em U, fotos |
| | **Equipamentos** | switches, firewalls, patch panels, tomadas, DIO, PDU, servidores, estações; portas, cabos, IPs e fotos |
| | **Cabos** | todos os cabos com as pontas, tipo, cor e comprimento; caminho completo |
| | **Circuitos (operadoras)** | links de internet/MPLS: lado A (rede da operadora) e lado Z (onde chega na filial) |
| | **Tipos de equipamento** | modelos com as portas já definidas (o equipamento nasce com as portas) e as funções (cores) |
| IPAM | **Prefixos** | redes em árvore (contêiner /16 da filial → /24 por VLAN), utilização, próximo IP livre, blocos livres |
| | **Endereços IP** | quem usa cada IP (equipamento + interface); faixas (pool DHCP, reservas) |
| | **VLANs e VRFs** | VLANs por grupo/filial, portas e prefixos de cada uma; VRFs |

## Primeiro uso, passo a passo

1. **Locais.** Em *Locais → Novo local*, cadastre o prédio da matriz (tipo *Prédio*). Dentro dele, crie o *CPD / sala técnica* e os andares. Em cada andar, crie as mesas como *Posto de trabalho*, por exemplo "Mesa 14 — Financeiro". O local filho herda a filial do pai.
2. **Rack.** Na tela do CPD, use *+ Rack*: informe a altura (42U, 12U…) e a largura.
3. **Tipos.** A migration já cadastra tipos genéricos prontos:
   - patch panel 24/48;
   - ponto de rede simples/duplo;
   - DIO 12/24 LC;
   - switch 24/48 + SFP+;
   - PDU de 8 tomadas;
   - quadro de 12 disjuntores;
   - estação de trabalho;
   - access point;
   - organizador.

   Para o modelo real, use *Tipos de equipamento → Novo tipo* (ex.: *Fortinet FortiGate 60F*) e adicione as portas em lote: `wan[1-2]`, `internal[1-5]`, entrada de energia.
4. **Equipamentos no rack.** Abra o rack e clique numa **U vazia** da elevação: o formulário já vem com rack, posição e face. Escolha o tipo e as portas são criadas sozinhas.
5. **Tomada e estação.** Na mesa, cadastre a tomada (tipo *Ponto de rede*, nome como na etiqueta da parede, ex.: `PT-112`). Cadastre também a estação de trabalho, **vinculando o ativo do inventário**: busque pelo nome, série ou patrimônio. Marque *Copiar as placas de rede do inventário* para trazer as NICs com o MAC.
6. **Cabos.** Na tela do equipamento, em cada porta use o botão de **conectar** (ícone de tomada). Escolha filial → equipamento → porta livre e informe etiqueta, tipo, cor e comprimento. Para um caminho completo do usuário:
   - `PC eth0` → `PT-112 frontal 1` (cabo de manobra da mesa);
   - `PT-112 traseira 1` → `PP-01 traseira 12` (cabo horizontal, crimpado no punch-down);
   - `PP-01 frontal 12` → `SW-CORE porta 12` (patch cord no rack).
7. **Operadora.** Cadastre a operadora em *Gestão → Fornecedores*. Em *Circuitos*, crie o circuito com a designação da fatura:
   - lado A = *rede da operadora* (ex.: "Internet");
   - lado Z = filial e CPD.

   No lado Z use *Conectar cabo* até a porta PON da ONT ou o DIO, e da ONT ao `wan1` do firewall.
8. **IPAM.**
   - Em *Prefixos*, crie o contêiner da filial (`10.10.0.0/16`, situação *Contêiner*) e as sub-redes por VLAN (`10.10.10.0/24` → VLAN 10 DADOS).
   - Dentro da sub-rede, crie a faixa do DHCP (*Faixa*, marcando *contar como utilizada*).
   - Use **Próximo IP livre** para os IPs fixos, ou o botão *IP* na porta do equipamento. Marque *IP primário* no IP de gerência.

## Rastreamento (trace)

O botão de **rastrear** (ícone de rota) aparece em toda porta com cabo, na lista de cabos e nos lados do circuito. O desenho segue o do NetBox:

- cada bloco é um equipamento, com a cor da função. Mostra filial, local, rack/U, a porta de entrada e a de saída (ex.: patch panel frontal 12 → traseira 12), os IPs e o link para o ativo;
- entre os blocos fica o cabo, na **cor real**, com etiqueta, tipo e comprimento. Trecho tracejado = cabo *planejado*;
- o resumo diz se o caminho está **completo** (termina numa porta ativa), **ativo** (todos os cabos conectados), quantos cabos e quantos metros;
- a partir de uma porta **de passagem** (tomada, patch panel, DIO, lado do circuito), o trace mostra os **dois sentidos**: do switch até a estação, passando pela tomada.

Fibra com **DIO e cabo tronco**:

- a bandeja traseira do DIO tem N posições (uma por fibra) e cada conector frontal é mapeado numa posição;
- o tronco liga bandeja com bandeja;
- o trace entra pelo conector 3 do DIO A e sai pelo conector 3 do DIO B, como no NetBox, por meio da pilha de posições.

Na tela do **equipamento**, a tabela de portas mostra duas informações por porta:

- **Ligado em**: a outra ponta do cabo (ex.: patch panel);
- **Destino final**: o equipamento ativo no fim do caminho (ex.: a estação PC-FIN-12 e o IP dela).

Na tela do **ativo** do inventário aparece onde ele está e em qual porta de switch chega.

## Energia e console

- **Quadro de energia**: equipamento com tomadas (disjuntores).
- **PDU**: entrada (`power_port`) e tomadas (`power_outlet`).
- **Fontes dos equipamentos**: `power_port`, com consumo máximo e alocado em W.

Ligue com cabo do tipo *Energia*. A tela da PDU mostra tomadas em uso e carga alocada contra o máximo da entrada. Portas de console (RJ-45/USB) ligam em servidor de console ou em patch panel.

## Fotos e documentos

Locais, racks, equipamentos, tipos, circuitos e cabos têm **Fotos**: o botão abre a câmera no celular, com várias fotos por registro. Há também **Documentos** para planta, manual, datasheet, certificação de cabo e termo de aceite da operadora.

A foto do **tipo** aparece em todos os equipamentos daquele modelo.

## Regras importantes

| Regra | Por quê |
|---|---|
| Uma porta tem no máximo um cabo; cabo precisa das duas pontas | integridade do caminho (UNIQUE no banco) |
| Tipos incompatíveis não se ligam (energia × rede, console × interface) | regra `COMPATIBLE_TERMINATION_TYPES` do NetBox |
| Interface virtual/LAG/Wi-Fi não recebe cabo; porta "marcada como conectada" também não | idem |
| Equipamento de **profundidade total** ocupa a U nas duas faces; meia profundidade deixa a outra face livre | ocupação real do rack |
| 0U (tomada, AP, ONT) não tem posição no rack | |
| Excluir equipamento/porta exclui os **cabos** ligados nele | sem cabo "pela metade" |
| IP único por VRF (e na tabela global); rede e broadcast recusados no IPv4 comum | `ENFORCE_GLOBAL_UNIQUE` / `IPAddress.clean()` |
| Prefixo duplicado recusado; faixas não se sobrepõem | |
| "Próximo IP livre" pula IPs cadastrados **e faixas** (o pool DHCP nunca é sugerido para IP fixo) | |

## Convenções sugeridas de nomes

| Objeto | Exemplo |
|---|---|
| Tomada na parede | `PT-112` (andar 1, ponto 12), igual à etiqueta física |
| Patch panel | `PP-01`, `PP-02` (de cima para baixo no rack) |
| Cabo horizontal | `H-0112` (mesmo número da tomada) |
| Patch cord no rack | `P-12` (porta do patch panel) |
| Fibra entre prédios | `TRONCO-OS2-01` |
| Rack | `RACK-CPD-01` |

## Consultas úteis (SQL)

```sql
-- Em qual porta de switch está cada estação do inventário (destino imediato do cabo da mesa)
SELECT a.name AS ativo, d.name AS equipamento, p.name AS porta, c.label AS cabo
FROM dcim_device d JOIN asset a ON a.id = d.asset_id
JOIN dcim_port p ON p.device_id = d.id
JOIN dcim_cable_termination t ON t.port_id = p.id JOIN dcim_cable c ON c.id = t.cable_id;

-- Ocupação dos racks
SELECT r.name, b.name AS filial, coalesce(sum(t.u_height), 0) AS u_ocupadas, r.u_height
FROM dcim_rack r JOIN branch b ON b.id = r.branch_id
LEFT JOIN dcim_device d ON d.rack_id = r.id AND d.position IS NOT NULL
LEFT JOIN dcim_device_type t ON t.id = d.device_type_id
GROUP BY r.id, b.name ORDER BY 3 DESC;

-- IPs cadastrados fora de qualquer prefixo
SELECT host(i.address) FROM ipam_ip_address i
WHERE NOT EXISTS (SELECT 1 FROM ipam_prefix p WHERE p.vrf_id IS NOT DISTINCT FROM i.vrf_id AND inet(host(i.address)) <<= p.prefix);

-- Portas de switch livres por equipamento
SELECT d.name, count(*) FILTER (WHERE t.id IS NULL AND NOT p.mark_connected) AS livres, count(*) AS total
FROM dcim_device d JOIN dcim_port p ON p.device_id = d.id AND p.kind = 'interface'
LEFT JOIN dcim_cable_termination t ON t.port_id = p.id
JOIN dcim_device_role r ON r.id = d.role_id AND r.name = 'Switch'
GROUP BY d.name ORDER BY 1;
```

## Próximas fases (não incluídas)

- Sugestões automáticas a partir de LLDP/CDP (SNMP), ARP do FortiGate e do scanner de rede.
- Importação de CSV e de um NetBox existente.
- Módulos/baias, stack (virtual chassis), wireless, VPN, virtualização, ASN/FHRP.
- Etiqueta de cabo e de tomada na impressora Argox (já integrada no estoque).
