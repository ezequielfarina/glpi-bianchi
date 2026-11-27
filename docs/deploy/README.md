
## Etiqueta de envio (impressora térmica Argox)

A etiqueta da saída para filial é impressa pelo navegador, no computador de quem registra a saída — o CT não
precisa enxergar a impressora. Padrão: papel **USER 101,6 × 152,4 mm (4 × 6 pol), retrato**, com o desenho do
modelo `Entrega_TI` deitado (girado 90°) para ocupar a etiqueta inteira.

1. Driver da Argox (Seagull) → *Preferências de impressão* → papel **USER (101,6 mm × 152,4 mm)**, retrato.
2. Na tela da etiqueta → *Tamanho da etiqueta*: o mesmo papel (fica salvo no navegador). Para outra bobina,
   escolha o tamanho ou "Outro tamanho…" e, se precisar, o conteúdo *Normal* / *Deitado 90°* / *Deitado 270°*.
3. No diálogo de impressão do Chrome/Edge: impressora Argox → **Mais definições** → **Tamanho do papel = USER
   101,6 × 152,4** (o mesmo do passo 1, não A4), **Margens: Nenhuma**, **Escala: Padrão**, sem cabeçalhos e rodapés.

Troubleshooting:

| Sintoma | Causa | Correção |
|---|---|---|
| Prévia do Chrome em A4 / erro ao imprimir | tamanho do papel do diálogo ≠ papel do driver | passo 3: escolher o papel USER; o Chrome só casa o `@page` com um papel que o driver tenha |
| Etiqueta cortada ou dividida em duas | escala ≠ 100% ou margens do navegador | Escala: Padrão, Margens: Nenhuma |
| Texto de cabeça para baixo | sentido de saída da bobina | trocar para *Deitado 270°* |
| Pula uma etiqueta em branco | sensor de gap não calibrado | calibrar o sensor no driver/painel da Argox |
