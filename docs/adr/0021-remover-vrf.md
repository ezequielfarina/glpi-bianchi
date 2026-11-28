# ADR-0021: Remover VRF do IPAM

- **Status:** aceito
- **Data:** 2026-11-28
- **Decisores:** Ezequiel Farina
- **Substitui em parte:** [ADR-0016](0016-dcim-ipam-cabeamento.md) (§ VRF)

## Contexto

O IPAM veio do modelo do NetBox, com VRF (`ipam_vrf`) e `vrf_id` em prefixo, faixa e IP. O ambiente do Grupo Bianchi
usa só a tabela de roteamento global com segmentação por **VLAN**; ninguém cadastrou VRF. O campo aparecia em todos
os formulários e filtros ("Todas as VRFs / Global"), confundindo o cadastro sem trazer informação.

## Decisão

Remover VRF do modelo, da API e da interface. Prefixos, faixas e IPs ficam **únicos globalmente** (mesma regra que a
tabela global já tinha). A migration aborta se encontrar qualquer registro numa VRF — nada é apagado em silêncio — e o
`down()` recria a estrutura vazia.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Só esconder na interface | reversível sem migration | coluna e regra continuam no código e nas consultas |
| Manter | pronto para MPLS/VRF-lite | complexidade sem uso real |

## Consequências

- Positivas: formulários e filtros mais simples; consultas de utilização sem `IS NOT DISTINCT FROM vrf_id`.
- Negativas: redes sobrepostas (ex.: duas filiais com 192.168.1.0/24) não podem ser cadastradas — hoje não ocorre
  (segmentação planejada por filial). Se surgir, reabrir com VRF-lite ou "contexto" por filial.
- Revisitar: aquisição/fusão com endereçamento sobreposto, ou VPN site-to-site com NAT de redes iguais.
