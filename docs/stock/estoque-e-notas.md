# Estoque, notas fiscais e etiqueta de envio

Estoque central de TI que abastece as filiais. Decisões: [ADR-0014](../adr/0014-estoque-e-notas-fiscais.md).

## Primeiros passos

1. **Estoque → Novo item** para cada item (ex.: Mouse USB, Teclado ABNT2, Monitor 24", CPU), com
   **estoque mínimo**. Marque **Controlar por patrimônio** para itens que precisam de número de patrimônio por
   unidade (monitor, CPU, notebook) e escolha o tipo de ativo.
2. **Entrada manual** com motivo *Saldo inicial* para lançar o que já existe hoje. Para itens com patrimônio,
   informe os números de série (um por linha, opcional) — os patrimônios são gerados automaticamente.
3. A partir daí, compras entram por **nota fiscal**.

## Nota fiscal

**Notas fiscais → Registrar nota**: fornecedor, número, série, emissão, recebimento, itens (quantidade × valor
unitário) e o **valor total da nota**. A diferença entre o total e a soma dos itens (frete, desconto, impostos) é
mostrada, sem impedir o registro.

- Registrar a nota **dá entrada no estoque**. Itens com patrimônio viram ativos *Em estoque* (aparecem em Ativos).
- A mesma nota (fornecedor + número + série) não é aceita duas vezes.
- Situação: **Registrada → Enviada para lançamento** (com data e quem enviou). O lançamento é feito por outro
  setor e não é acompanhado no sistema. Na lista, o filtro *A enviar* permite marcar várias de uma vez; o envio
  pode ser desfeito.
- Anexe o DANFE/XML na própria nota.
- Depois de salva, só o cabeçalho é editável. Para corrigir itens, exclua a nota e registre de novo — permitido
  enquanto nada do que ela trouxe saiu do estoque.

## Saída para filial e etiqueta de envio

**Saída para filial**: filial, **setor** e **A/C** (quem recebe), itens e quantidades. Para itens com patrimônio,
escolha as unidades — elas passam a *Em uso* na filial (com histórico no ativo). Quantidade acima do saldo é
recusada.

Ao registrar, abre a **etiqueta de envio** (modelo `Entrega_TI`): remetente, destinatário (filial + código, ex.
`CHAPECÓ - 08`), `SETOR A/C PESSOA`, logo e, com mais de um volume, `VOLUME N/M`. Os textos podem ser ajustados
antes de imprimir. Reimpressão: ícone de impressora em *Movimentações* ou no detalhe do item.

### Impressão na Argox

A etiqueta é impressa pelo navegador, no computador de quem registra a saída (o CT não precisa enxergar a
impressora). O tamanho da página é o do **papel do driver**: padrão **102,4 × 74,9 mm** (papel `USER` da
ArgoxBancada), ajustável em *Papel — largura/altura* (fica salvo no navegador).

1. Driver da Argox (*Preferências de impressão*): papel `USER (102,4 mm × 74,9 mm)`.
2. Na tela da etiqueta, o mesmo tamanho em *Papel*.
3. No diálogo do Chrome/Edge: impressora Argox → **Mais definições** → **Tamanho do papel = USER**, **Margens:
   Nenhuma**, **Escala: Padrão**, sem cabeçalhos e rodapés.

| Sintoma | Causa | Correção |
|---|---|---|
| Prévia do Chrome em A4/Carta, sai em 2 etiquetas | tamanho em *Papel* ≠ papel do driver; o Chrome só usa o papel do driver quando a página pede exatamente o mesmo tamanho | igualar *Papel* ao papel `USER` do driver |
| Etiqueta cortada | escala ≠ 100% ou margens do navegador | Escala: Padrão, Margens: Nenhuma |
| Saiu de cabeça para baixo | sentido da bobina | driver em *Retrato 180°* |
| Pula uma etiqueta em branco | sensor de gap não calibrado | calibrar o sensor no driver/painel da Argox |

## Estoque × Ativos

Equipamento guardado no estoque **não aparece em Ativos** (nem nos totais da visão geral): ele está no Estoque, nas
unidades do item. Quando sai para uma filial, passa a *Em uso* e entra na lista de Ativos. Em **Ativos** o filtro
*Em uso / Em uso + estoque / Só os guardados* mostra os guardados quando preciso; se uma busca (patrimônio, série)
só encontra equipamento guardado, a tela avisa e oferece mostrar. O detalhe do ativo mostra de onde ele veio (item,
data de entrada, nota fiscal) e para onde foi.

## Ajuste, histórico e consumo

- **Ajustar** (sempre com motivo): sem patrimônio, quantidade + ou −; com patrimônio, baixa de unidades escolhidas
  (perdido, descartado, baixado por defeito). O saldo nunca fica negativo.
- **Movimentações**: entradas, saídas e ajustes com filtros por tipo, filial e período.
- **Consumo por filial**: o que cada filial recebeu no período.
- **A comprar**: itens abaixo do mínimo e quanto falta; o número aparece no menu *Estoque*.

Tudo isso fica registrado em [Auditoria](../audit/auditoria.md).
