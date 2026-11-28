# ADR-0018: Listas editáveis nos campos e desenho do equipamento no mapa

- **Status:** aceito
- **Data:** 2026-11-27
- **Decisores:** Ezequiel Farina

## Contexto

Campos de escolha (sistema operacional da VM, função do equipamento, plataforma/grupo do cluster, tipo de
circuito) tinham listas fixas ou só editáveis em outra tela. O sistema operacional era um `<datalist>` com
valores no código, que o navegador desenha ao lado do campo e fora do visual do projeto.
O mapa da rede (ADR-0017) desenhava todo nó como esfera ou círculo; a leitura de "o que é o quê" dependia da cor.

## Decisão

1. **`dcim_platform`** (porta de `dcim.Platform` do NetBox): lista editável de sistemas operacionais/plataformas.
   `virt_machine.platform` continua **texto** (aceita valor fora da lista; nada quebra se a lista mudar).
   Renomear na lista renomeia nas VMs; excluir é recusado enquanto alguma VM usa o nome.
   A migration semeia a lista com os valores antigos e com o que já foi digitado nas VMs.
2. **`dcim_device_role.icon`** (`VARCHAR(20)`, opcional): desenho do equipamento no mapa
   (`switch`, `firewall`, `server`, `hypervisor`, `patch_panel`, `jack`, `ont`, `ap`…; lista em
   `DcimChoices::ROLE_ICONS`). Nulo = deduzido pelo nome da função. A migration preenche as funções semeadas.
3. **Componente `LookupSelect`** no frontend: combobox próprio (abre logo abaixo do campo, filtra, teclado,
   tema do projeto). Para Técnico/Administrador, cadastra, renomeia e exclui itens ali mesmo; a função também
   edita cor e desenho. Cache por lista compartilhado entre os campos abertos (`lib/lookups.ts`).
   A exclusão de função passa a considerar também as VMs que a usam.
4. **Desenhos vetoriais em canvas** (`lib/netIcons.ts`), sem imagens externas: frente do equipamento
   (portas, LEDs, baias de disco, tomadas, disjuntores). No 3D, equipamento de rack vira **caixa** com a frente
   como textura, sempre em ¾ para a câmera (frente legível, topo e lateral visíveis); os demais são sprites.
   No 2D, o mesmo desenho via `drawImage` de canvas em cache. Força de colisão (`d3-force-3d`) evita sobreposição.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| `virt_machine.platform_id` (FK) | integridade | migration de dados, campo livre deixa de existir, inventário do agente traz texto |
| Ícones SVG/PNG de fabricante | realismo | licença das marcas, peso, um arquivo por modelo |
| Modelos 3D (glTF) por tipo | visual máximo | peso e tempo de carga; manutenção de assets |
| `<select>` nativo estilizado | simples | a lista aberta é do sistema operacional; não permite editar itens |

## Consequências

- Positivas: listas mantidas por quem usa, no próprio formulário; mapa lido pela forma do equipamento, não só pela cor.
- Negativas / dívidas assumidas: `platform` em texto permite variações de grafia fora da lista; desenhos são genéricos por função (não por modelo).
- Revisitar: desenho por **tipo de equipamento** (quantidade real de portas) se o mapa ficar denso em switches diferentes.
