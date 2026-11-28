# Virtualização, caminho até a internet e mapa da rede

Decisão: [ADR-0017](../adr/0017-virtualizacao-e-mapa.md). Modelo: `virtualization` do NetBox (clusters, VMs,
interfaces, discos), com o host físico sendo um **equipamento** do DCIM.

## Passo a passo

1. **Clusters / hosts → Novo cluster**: nome (`PVE-MATRIZ`), plataforma (Proxmox VE, ESXi, Hyper-V, XCP-ng, KVM, AHV),
   filial, versão e URL de gerência (`https://pve01:8006` — vira o botão *Console*).
2. **Adicionar host**: escolha o servidor físico já cadastrado em *Equipamentos* (no rack, com cabos).
   Capacidade (threads, RAM, storage) é opcional: em branco, vem do inventário do GLPI Agent do ativo vinculado.
3. **Máquinas virtuais → Nova VM**: cluster, host, vCPU (aceita `2,5`), RAM, VMID, SO, situação.
   Vincule ao ativo do inventário quando a VM roda o GLPI Agent (copia as NICs com MAC).
4. Na VM: **interfaces** (`net0`, bridge `vmbr0`, MAC, VLAN acesso/tronco), **IPs** (marque *primário*),
   **discos** (`scsi0` 100 GB em `local-lvm` — o total da VM passa a ser a soma), capturas de tela e documentos.

Todo campo tem o **!** ao lado do rótulo: passe o mouse ou toque para ver como preencher, com exemplo.

## Caminho até a internet (reverso)

Na VM e no equipamento (painel *Caminho até a internet*): **VM → virtualizador → host físico → cabo → switch →
… → firewall → ONT/roteador → circuito da operadora → rede da operadora**. Cada degrau é clicável para editar e
mostra os cabos (com patch panels e tomadas no meio) pelo rastreamento do DCIM.

Busca: BFS pelas interfaces cabeadas até o **circuito mais próximo** (limite de 500 equipamentos).
Quando não chega, diz o porquê e o que falta:

| `reason` | Significado | Como resolver |
|---|---|---|
| `no_cable` | o equipamento não tem interface com cabo | conectar o cabo da placa de rede |
| `no_route` | tem cabo, mas nenhum caminho chega a um circuito | ligar o lado Z do circuito na ONT/roteador/firewall |

Trechos com cabo *planejado* aparecem tracejados; caminho incompleto (cabo terminando em patch panel sem
continuação) é sinalizado em amarelo.

## Mapa da rede

*Infraestrutura → Mapa da rede*. WebGL (three.js) em 3D ou canvas em 2D — escolha salva no navegador; no celular abre em 2D.

| Controle | Efeito |
|---|---|
| Lógico | equipamento a equipamento; patch panels, tomadas e DIO ficam no detalhe da ligação (`via`) |
| Físico | cabo a cabo, com cada elemento passivo |
| VMs | mostra clusters e VMs ligados aos hosts |
| Busca | nome ou IP — voa até o nó e abre o painel |

Cores: nó = cor da **função** do equipamento; ligação = **cor real do cabo** (energia em amarelo, console em
cinza, VM/host tracejado); partículas = cabo ativo. Clique no nó: modelo, rack/U, IP, portas conectadas, VMs e o
caminho até a internet sem sair do mapa. Passivos sem ligação (organizador de cabos) não entram no mapa.

## Consultas úteis

```sql
-- ocupação por host (VMs ligadas)
SELECT d.name AS host, count(v.id) AS vms, sum(v.vcpus) AS vcpu, sum(v.memory_mb) / 1024 AS ram_gb,
       d.cpu_threads, d.memory_mb / 1024 AS cap_ram_gb
FROM dcim_device d JOIN virt_machine v ON v.device_id = d.id AND v.status = 'active'
GROUP BY d.id ORDER BY ram_gb DESC;

-- VMs sem IP primário ou sem host definido
SELECT c.name AS cluster, v.name FROM virt_machine v JOIN virt_cluster c ON c.id = v.cluster_id
WHERE v.primary_ip_id IS NULL OR v.device_id IS NULL ORDER BY 1, 2;
```
