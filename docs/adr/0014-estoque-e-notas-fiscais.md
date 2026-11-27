# ADR-0014: Estoque central de TI com razão de movimentações e nota fiscal simplificada

- **Status:** aceito
- **Data:** 2026-11-27
- **Decisores:** Ezequiel Farina

## Contexto

O setor de TI mantém um estoque central (mouse, teclado, monitor, CPU…) e entrega às filiais. A compra
gera uma nota fiscal que precisa ser registrada e enviada ao setor de lançamento. Requisitos do usuário:

- nota com poucos campos: número, fornecedor, itens, valor unitário e valor total;
- alguns itens são controlados por **patrimônio desde a entrada** (monitor, CPU); outros só por quantidade;
- acompanhar se a nota já foi enviada para lançamento (sem e-mail por enquanto);
- alerta de estoque mínimo **só no sistema** (sem Teams/Telegram por enquanto);
- sem pedido de compra nesta fase.

## Decisão

1. **Módulo `Stock`** com quatro tabelas: `stock_item`, `invoice`, `invoice_item`, `stock_movement`.
2. **Saldo = soma do razão** (`stock_movement.quantity`), nunca uma coluna editável. Tipos: `in` (> 0),
   `out` (< 0, com filial), `adjust` (qualquer sinal, com motivo). CHECK garante o sinal por tipo.
3. **Concorrência:** toda operação trava as linhas de `stock_item` envolvidas (`SELECT … FOR UPDATE`, em ordem
   de id) antes de conferir o saldo — duas saídas simultâneas não deixam o estoque negativo.
4. **Itens com patrimônio (`tracked`):** a entrada cria um ativo `in_stock` por unidade (tipo de ativo do item,
   patrimônio pela sequência existente, série opcional) e uma movimentação por unidade com `asset_id`.
   A saída escolhe os ativos, que passam a `in_use` na filial de destino (com histórico no ativo); a baixa por
   ajuste muda o ativo para `lost`/`disposed`/`retired`.
5. **Nota fiscal:** cabeçalho (fornecedor, número sem zeros à esquerda, série, emissão, total informado,
   observações) + itens. Registrar a nota dá entrada no estoque na mesma transação. O total informado pode
   diferir da soma dos itens (frete, desconto, impostos) — a diferença é exibida, não bloqueada.
   UNIQUE `(supplier_id, number, series)` impede registrar a mesma nota duas vezes.
6. **Situação da nota:** `registered → sent` (enviada para lançamento), com data e autor; o envio pode ser desfeito.
   O lançamento é feito por outro setor da empresa, então o sistema não acompanha essa etapa
   (revisão de 2026-11-27: a situação `posted` foi removida — migration `Version20261127140000`).
7. **Imutabilidade:** itens da nota não são editáveis depois de salvos. Corrigir = excluir a nota, permitido só
   enquanto nada do que ela trouxe saiu do estoque (patrimônio ainda `in_stock` sem movimentação posterior e
   saldo suficiente para os itens por quantidade). A exclusão desfaz as movimentações (CASCADE) e apaga os
   ativos criados por ela.
8. **Custo médio ponderado** calculado das entradas com valor; registrado como `unit_cost` em saídas/ajustes.
9. **Alerta de mínimo** só na interface: contador no menu (`/api/ui/stock/low-count`) e lista "a comprar".
10. Anexos (DANFE/XML) usam o módulo de anexos existente (`owner_type = invoice`).
11. **Etiqueta de envio** (revisão de 2026-11-27, modelo `Entrega_TI.odt`): a saída grava o setor de destino
    (`stock_movement.department`) e abre a etiqueta — remetente, destinatário (filial + código, ex.
    "CHAPECÓ - 08"), "SETOR A/C PESSOA", logo e volume N/M. Gerada no navegador (HTML + `@page` no tamanho exato,
    105×75 mm por padrão, ajustável) e impressa pelo driver da impressora térmica (Argox): preto puro, logo em P&B,
    sem servidor de impressão nem linguagem PPLA/PPLB. Reimpressão pela lista de movimentações
    (`GET /api/ui/stock/shipments/{batch}`).

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Coluna `quantity` em `stock_item` atualizada a cada operação | leitura trivial | perde auditoria, sujeita a corrida e a "acertos" manuais; diverge do histórico |
| Itens da nota editáveis com recálculo | conveniente | precisaria desfazer/refazer patrimônios já entregues; complexidade e risco de inconsistência |
| Importar XML da NF-e (layout 4.00) | dados completos e sem digitação | o usuário pediu registro simples; fica como evolução (os campos já existem) |
| Reusar `network_alert` + canais para o mínimo | notificação externa pronta | usuário pediu só no sistema; mistura domínio de rede com estoque |

## Consequências

- Positivas: histórico completo (quem, quando, para qual filial, de qual nota); saldo auditável; patrimônio
  existe desde a chegada, então o monitor guardado já aparece em Ativos como "Em estoque".
- Negativas / dívidas: um único estoque (central) — sem saldo por almoxarifado; sem pedido de compra; sem
  notificação externa do mínimo; sem e-mail ao setor de lançamento.
- Revisitar: importação do XML da NF-e, pedido de compra, envio por e-mail (`symfony/mailer`) e notificação
  do mínimo pelos canais do ADR-0013 quando o usuário aprovar.
