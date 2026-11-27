
## Etiqueta de envio (impressora térmica Argox)

A etiqueta da saída para filial é impressa pelo navegador, no computador de quem registra a saída — o CT não
precisa enxergar a impressora. **A página não define tamanho de papel**: vale o papel padrão configurado no
driver da impressora, e o desenho do modelo `Entrega_TI` se ajusta a ele (uma página por volume, centralizado).

- Driver da Argox (Seagull) → *Preferências de impressão*: papel e orientação da etiqueta real
  (ex.: USER 101,6 × 152,4 em **Paisagem** para ocupar a etiqueta toda).
- No diálogo do Chrome/Edge: **Margens: Nenhuma**, **Escala: Padrão**, sem cabeçalhos e rodapés.

| Sintoma | Causa | Correção |
|---|---|---|
| Desenho pequeno no meio da etiqueta | papel do driver em retrato para um desenho deitado | driver em *Paisagem* |
| Saiu de cabeça para baixo | sentido da bobina | driver em *Paisagem 180°* |
| Sai em 2 etiquetas | papel do driver maior que a etiqueta física | papel do driver com o tamanho real da etiqueta |
| Pula uma etiqueta em branco | sensor de gap não calibrado | calibrar o sensor no driver/painel da Argox |
