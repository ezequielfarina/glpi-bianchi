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

**Prévia antes de imprimir:** a tela *Saída para filial* mostra ao lado a etiqueta exatamente como vai sair,
atualizada enquanto você escolhe a filial, o setor, o A/C e os volumes. Embaixo da prévia aparece a impressora em uso
(*Impressora: 192.168.0.253:9100 · PPLA*); o Administrador troca o IP, a porta ou a linguagem ali mesmo em
**Alterar** (o mesmo valor de *Configurações*).

**Automática (recomendado):** com a impressora configurada, ao registrar a saída a etiqueta sai sozinha na Argox —
uma por volume (`VOLUME 1/3`, `2/3`…). Na saída, informe **Volumes** e deixe marcado *Imprimir etiqueta na
impressora*. Reimpressão: ícone de impressora em *Movimentações* → **Imprimir na impressora**. A tela da etiqueta
mostra a **prévia exata** do que vai para a impressora.

Configuração (uma vez), em **Configurações**:

| Campo | Valor |
|---|---|
| Impressora de etiquetas: IP | IP da Argox (ex.: `192.168.110.80`) |
| porta | `9100` |
| linguagem | a emulação da impressora: **PPLA** (a ArgoxBancada, 192.168.0.253), PPLB ou PPLZ/ZPL |
| resolução | `203` dpi (OS-214/OS-2140) ou `300` |
| largura / altura | `102` × `75` mm (papel `USER 102,4 × 74,9`) |
| remetente | `TI` |

Depois, **Imprimir etiqueta de teste**.

Requisitos e ajustes:

- Descubra a emulação mandando um texto de teste em cada linguagem — a que imprimir é a certa:
  ```bash
  printf '\002L\nD11\n121100000500050TESTE PPLA\nE\n' | nc -q1 IP-DA-ARGOX 9100
  printf '\nN\nA50,50,0,4,1,1,N,"TESTE PPLB"\nP1\n' | nc -q1 IP-DA-ARGOX 9100
  printf '^XA^FO50,50^A0N,40,40^FDTESTE PPLZ^FS^XZ\n' | nc -q1 IP-DA-ARGOX 9100
  ```
  Não troque a emulação da impressora só por causa do sistema: as três são suportadas, e o driver do Windows
  depende da emulação atual.
- Em PPLA, se a etiqueta de teste sair **com o fundo preto e o texto em branco**, escolha *PPLA (inverter imagem)*.
- O CT precisa alcançar a impressora na porta 9100. Se houver FortiGate entre eles, libere
  `192.168.110.55 → IP-da-Argox TCP/9100`. Teste no CT:
  ```bash
  nc -vz IP-DA-ARGOX 9100
  ```
- Etiqueta saindo deslocada ou pulando uma em branco: calibre o sensor de gap (no utilitário da Argox ou segurando
  FEED); confira largura/altura em Configurações.
- Impressora desligada/inalcançável: a saída é registrada normalmente e a tela da etiqueta abre com o erro e as
  alternativas abaixo.

**Alternativas** (sem impressora configurada, ou em *Outras opções*):

- **Baixar .odt** — o seu modelo `Entrega_TI` preenchido, para imprimir pelo LibreOffice (como era feito à mão).
  O modelo fica em `resources/labels/entrega-ti.odt`; para trocar o layout, mantenha a ordem: `REMETENTE: <texto>`,
  `DESTINATÁRIO:`, parágrafo da filial, parágrafo "setor A/C pessoa".
- **Pelo navegador** — depende do Chrome casar a página com o papel do driver (*Tamanho do papel = USER*,
  *Margens: Nenhuma*); com o driver em retrato, o Chrome manda paisagem e a impressão pode sair deslocada.

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
