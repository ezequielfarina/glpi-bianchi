# Auditoria

Tela **Sistema → Auditoria** (só Administrador). Duas abas:

| Aba | O que registra | Retenção |
|---|---|---|
| **Ações no sistema** | tudo que é feito pela interface: cadastro, alteração e exclusão (ativos, filiais, fornecedores, contatos, contratos, licenças, anexos, cadastros auxiliares, usuários, configurações), estoque (entrada, saída para filial, ajuste, itens), notas fiscais (registro, alteração, envio ao lançamento, exclusão), ferramentas (varreduras, ping, traceroute, portas, DNS, whois, MAC), monitoramento (agendamentos, canais, reconhecimento de alertas), forçar inventário, exibir chave de licença | permanente |
| **Acessos e contas** | login, falhas, bloqueios, 2FA, troca de senha, ações sobre contas | 365 dias |

Cada linha de **Ações** mostra quando, a ação, o objeto (com link quando ele ainda existe), o usuário e o IP e um
resumo do que foi enviado. Clique na linha para ver o detalhe completo (método, caminho, status e o JSON).

- **Exclusões** guardam o nome do que foi apagado (lido antes de excluir).
- **Segredos não aparecem**: senhas, tokens, chaves de licença, community SNMP e destino de canais de notificação
  são gravados como `***`.
- Só ações **concluídas** entram. Uma saída recusada por saldo insuficiente, por exemplo, não aparece.
- A trilha é **somente inclusão**: o banco recusa alteração e exclusão de registros (trigger `trg_audit_log_guard`).
- Login, 2FA e logout ficam na aba *Acessos*; o que o GLPI Agent envia fica em *Inventários recebidos*; o histórico
  campo a campo de cada ativo continua no detalhe do ativo.

## Consultas úteis (SQL)

```sql
-- o que um usuário fez hoje
SELECT occurred_at, action, subject, details->'request' FROM audit_log
WHERE actor = 'fulano' AND occurred_at >= current_date ORDER BY occurred_at;

-- todas as exclusões dos últimos 30 dias
SELECT occurred_at, actor, action, subject FROM audit_log
WHERE method = 'DELETE' AND occurred_at > now() - interval '30 days' ORDER BY occurred_at DESC;

-- histórico de uma nota fiscal (id 12)
SELECT occurred_at, actor, action, details FROM audit_log
WHERE entity_type = 'invoice' AND entity_id = '12' ORDER BY occurred_at;
```

Detalhes técnicos e decisões: [ADR-0015](../adr/0015-auditoria-de-acoes.md).
