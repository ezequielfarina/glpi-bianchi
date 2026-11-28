# ADR-0017: Virtualização, caminho até a internet e mapa da rede

- **Status:** aceito
- **Data:** 2026-11-27
- **Decisores:** Ezequiel Farina

## Contexto

Com o DCIM e o cabeamento (ADR-0016) faltam três respostas:

- **Onde roda esta VM?** Em qual virtualizador (Proxmox, ESXi, Hyper-V), em qual servidor físico e com quantos recursos.
- **Por onde ela sai para a internet?** O caminho é VM → host → switch(es) → firewall → link da operadora → cabo da operadora.
- **Como a rede está ligada?** Um mapa de quem está ligado em quem, sem abrir equipamento por equipamento.

A referência continua sendo o NetBox 4.7.6: `virtualization/models/{clusters,virtualmachines}.py`.

## Decisão

### 1. Modelo (porta de `virtualization` do NetBox)

| NetBox | GLPI Bianchi | Observações |
|---|---|---|
| ClusterType | `virt_cluster_type` | seed: Proxmox VE, VMware ESXi/vSphere, Hyper-V, XCP-ng, KVM, Nutanix AHV |
| ClusterGroup | `virt_cluster_group` | |
| Cluster | `virt_cluster` | tipo, grupo, filial/local, status, **versão**, **URL de gerência** (link para a console web) |
| Device.cluster | `dcim_device.cluster_id` | o host é o equipamento físico já documentado (rack, portas, cabos) |
| — | `dcim_device.cpu_threads`, `memory_mb`, `storage_gb` | capacidade do host; se vazia, vem do inventário do agente vinculado (threads dos processadores, `memory_mib`, soma dos discos) |
| VirtualMachine | `virt_machine` | cluster (obrigatório), host (`device_id`, precisa ser do cluster), função, status, SO, **VMID**, vCPU (decimal), RAM (MB), disco (GB = soma dos discos quando houver), ativo do agente (único), IP primário |
| VMInterface | `virt_interface` (+ `virt_interface_vlan`) | MAC, MTU, **bridge/port group** (`vmbr0`, `vSwitch0/VM Network`), modo 802.1Q e VLANs |
| VirtualDisk | `virt_disk` | tamanho (GB) e **storage** (local-lvm, ceph, datastore1) |
| IPAddress.assigned_object | `ipam_ip_address.vm_interface_id` | FK real; CHECK impede IP ao mesmo tempo em porta física e em interface de VM |

As VMs ficam fora de `dcim_device` porque têm recursos e regras próprios, como no NetBox. A VM pode apontar para o ativo do inventário: o GLPI Agent instalado na VM a inventaria como computador.

### 2. Caminho até a internet (`TopologyService::pathToInternet`)

1. Monta o **grafo lógico**: para cada porta "ponta de caminho" com cabo, o `CableTracer` (ADR-0016) dá o destino. Patch panels, tomadas e DIO somem e viram detalhe do trecho. Cada aresta guarda a porta de saída e a de chegada.
2. Uma porta cujo caminho termina numa **terminação de circuito** liga o equipamento ao circuito, e dele à rede da operadora.
3. Faz **BFS** do host da VM até o circuito mais próximo e devolve os trechos em ordem, cada um com o trace completo (cabos, patch panels, cores e metragem).
4. Sem caminho, devolve o que foi alcançado e o motivo:
   - host sem cabo;
   - nenhum caminho até um circuito.

   A tela usa o motivo para mostrar o próximo cadastro a fazer.

### 3. Mapa da rede

- `GET /api/ui/dcim/topology` devolve nós (equipamentos, circuitos, nuvem da operadora e, opcionalmente, VMs) e arestas em dois modos:
  - **lógico**: equipamento ativo ↔ equipamento ativo, passivos colapsados;
  - **físico**: cabo por cabo, incluindo patch panel e tomada.
- A interface usa **3d-force-graph** (three.js/WebGL, MIT), com alternância para 2D (force-graph). As cores seguem o cabo e a função do equipamento e há partículas no sentido do tráfego. Clicar num nó abre o painel de detalhes.
- A rota é carregada sob demanda (lazy chunk): o three.js só baixa ao abrir o mapa.

### 4. Ajuda contextual

Todo campo de cadastro tem um ícone de ajuda (passar o mouse ou clicar) com explicação e exemplo. Os textos ficam num dicionário único (`frontend/src/lib/fieldHelp.ts`) por contexto e campo, reaproveitado por todos os formulários.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| VM como `dcim_device` com função "VM" | mistura capacidade física com alocação virtual e não tem disco/interface virtual |
| Sincronizar com a API do Proxmox/vCenter já nesta fase | o pedido foi cadastro manual; fica como próxima fase (as colunas `vmid` e `management_url` já preparam isso) |
| Mapa em SVG estático (dagre/elk) | layout rígido e sem interação 3D; força dirigida se adapta a filiais diferentes |
| Cytoscape.js | bom para 2D, sem 3D/WebGL nativo |

## Consequências

- **Positivas:**
  - a VM mostra o caminho físico inteiro até a operadora;
  - o cluster mostra o recurso alocado contra a capacidade dos hosts;
  - o mapa dá a visão geral e leva aos detalhes.
- **Negativas:**
  - o grafo lógico roda um trace por porta conectada a cada requisição, o que é aceitável na escala de filiais (centenas de portas);
  - sem sincronização automática com o hipervisor, os recursos dependem do cadastro.
- **Revisitar:**
  - importar VMs via API do Proxmox/vCenter;
  - materializar o grafo se o mapa passar de alguns milhares de portas.
