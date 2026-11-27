
## Etiqueta de envio (impressora térmica Argox)

A etiqueta da saída para filial é impressa pelo navegador, no computador de quem registra a saída — o CT não
precisa enxergar a impressora. Padrão: **100 × 75 mm, paisagem, conteúdo normal** (o modelo `Entrega_TI`).

1. Driver da Argox (Seagull) → *Preferências de impressão* → *Papel de etiquetas* → **Novo…**: nome
   `Etiqueta TI`, largura **100 mm**, altura **75 mm** (meça a etiqueta e use o tamanho real dela).
   O papel `USER 101,6 × 152,4` (4 × 6 pol) **cobre duas etiquetas de 75 mm** — é o que fazia a impressão sair
   em 2 etiquetas e girada.
2. Na tela da etiqueta → *Tamanho da etiqueta*: o mesmo tamanho do passo 1 (fica salvo no navegador).
3. No diálogo de impressão do Chrome/Edge: impressora Argox → **Mais definições** → **Tamanho do papel =
   Etiqueta TI** (o mesmo do passo 1), **Margens: Nenhuma**, **Escala: Padrão**, sem cabeçalhos e rodapés.

Troubleshooting:

| Sintoma | Causa | Correção |
|---|---|---|
| Prévia do Chrome em A4 / erro ao imprimir | tamanho do papel do diálogo ≠ papel do driver | passo 3; o Chrome só casa o `@page` com um papel que o driver tenha |
| Sai em 2 etiquetas | papel do driver maior que a etiqueta (ex.: 4 × 6 pol) | passo 1: papel com o tamanho real da etiqueta |
| Etiqueta cortada | escala ≠ 100% ou margens do navegador | Escala: Padrão, Margens: Nenhuma |
| Saiu girada / de cabeça para baixo | orientação do driver ou sentido da bobina | driver em *Paisagem* (ou *Paisagem 180°*); na tela, conteúdo *Normal* |
| Pula uma etiqueta em branco | sensor de gap não calibrado | calibrar o sensor no driver/painel da Argox |
