# ADR-0022: Documentação de túneis VPN IPsec importada do FortiGate

- **Status:** aceito
- **Data:** 2026-11-28
- **Decisores:** Ezequiel Farina

## Contexto

As filiais se ligam à matriz (e a parceiros, como bancos) por túneis IPsec nos FortiGates. Hoje a documentação está
espalhada entre a própria config e planilhas: qual rede passa por qual túnel, quem é o outro lado, qual proposta, onde
está a PSK. Quando um túnel não sobe, o diagnóstico começa comparando à mão as duas configs.

O NetBox tem um módulo VPN (`netbox/vpn`: Tunnel, TunnelTermination, IKEPolicy/IKEProposal, IPSecPolicy/IPSecProfile),
genérico e normalizado, pensado para cadastro manual.

## Decisão

1. **Fonte = configuração do FortiGate**, colada (`show [full-configuration] vpn ipsec …`) ou o backup `.conf`.
   O parser lê a árvore do FortiOS (aspas multilinha, VDOMs, `config global`) e extrai: fase 1 (`phase1-interface` e
   `phase1` policy-based), fases 2 com seletores resolvidos para CIDR (subnet, ip, range, objetos e grupos de endereço),
   IP local (`local-gw` ou IP da WAN), IP do túnel, rotas estáticas pelo túnel e zona SD-WAN.
2. **Modelo achatado no estilo FortiOS** (`vpn_tunnel` = fase 1, `vpn_phase2`), não o do NetBox: o que se documenta
   é exatamente o que o FortiGate tem, com os nomes que o técnico vê na CLI. Parâmetros não modelados vão em `params` (JSONB).
3. **PSK nunca é guardada** (decisão do usuário): valores `ENC …`, `psksecret`, `ppk-secret`, senhas e chaves privadas são
   descartados no extrator; o texto colado não é gravado, não vai para log, e o campo `config_text` é mascarado na
   auditoria. Documenta-se só `psk_location` (onde a chave está — cofre); a tela recusa algo que pareça a própria chave.
4. **Reimportação idempotente** por (equipamento, VDOM, nome da fase 1): atualiza parâmetros, preserva documentação
   (outro lado, fornecedor, circuito, local da PSK, observações) e marca `present = false` o que saiu da config.
5. **Pareamento automático**: o remote-gw de A é o IP local de B (preferência ao par mútuo; hub/dial-up aceita vários
   spokes). Com os dois lados no inventário, a tela compara parâmetro a parâmetro e diagnostica: versão/modo IKE,
   autenticação, interseção de proposta e DH (fase 1 e 2), PFS, keylife e **seletores espelhados**. Campo omitido no
   `show` usa o padrão do FortiOS 7.x, sinalizado.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Modelo do NetBox (políticas/propostas normalizadas) | padrão de mercado, reaproveitável | não espelha a CLI do FortiOS; cadastro manual pesado |
| Cadastro manual | sem parser | desatualiza no primeiro ajuste; erro de digitação |
| API REST do FortiGate (`/api/v2/cmdb/vpn.ipsec/phase1-interface`) | automático, status ao vivo | token em cada FortiGate, rota CT → gerência; fica para uma fase 2 |
| Guardar a PSK cifrada | tudo num lugar | decisão do usuário: não guardar segredo de túnel no inventário |

## Consequências

- Positivas: documentação fiel à config, atualizada por colar; "por que não sobe" respondido na tela; redes do túnel
  ligadas aos prefixos do IPAM; comandos de troubleshooting prontos.
- Negativas: depende de importar de novo após mudanças; `show` sem `full-configuration` omite padrões (o diagnóstico
  supõe o padrão e avisa).
- Revisitar: status ao vivo via API REST (token read-only), túneis no mapa da rede, FortiManager.
