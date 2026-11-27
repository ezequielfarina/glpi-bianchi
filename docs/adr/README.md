# Architecture Decision Records

Registro das decisões de arquitetura, no formato de [Michael Nygard](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions).
Para uma nova decisão, copie [`0000-template.md`](0000-template.md) usando o próximo número.

| # | Decisão | Status |
|---|---|---|
| [0001](0001-reescrita-em-vez-de-fork.md) | Reescrever em vez de fazer fork e poda do GLPI | aceito |
| [0002](0002-stack-symfony-postgresql.md) | Stack: PHP/Symfony + API Platform + PostgreSQL + Valkey | aceito |
| [0003](0003-modelo-de-ativos.md) | Tabela única de ativos com atributos em JSONB | aceito |
| [0004](0004-hierarquias-ltree.md) | Hierarquias com `ltree` mantido por trigger | aceito |
| [0005](0005-ingestao-assincrona.md) | Ingestão assíncrona com payload bruto persistido | aceito |
| [0006](0006-deploy-ct-lxc.md) | Deploy em CT LXC (Proxmox) com systemd, sem Docker | aceito |
| [0007](0007-frontend-build-versionado.md) | Frontend Vue 3 com build estático versionado | aceito |
| [0008](0008-acionamento-remoto-do-agente.md) | Acionamento remoto do agente (/now) e frequência no painel | aceito |
| [0009](0009-gestao-fornecedores-contratos-licencas.md) | Gestão: fornecedores, contratos, licenças, anexos, patrimônio | aceito |
| [0010](0010-matriz-e-filiais.md) | Matriz e filiais com mapa e vínculo automático por rede | aceito |
| [0011](0011-autenticacao-e-perfis.md) | Login individual: Argon2id, sessão em cookie, 2FA TOTP, perfis, HTTPS | aceito |
| [0012](0012-ferramentas-scanner-de-rede.md) | Ferramentas: scanner de rede (nmap em worker com CAP_NET_RAW) e calculadora de sub-rede | aceito |
| [0013](0013-monitoramento-alertas-diagnostico.md) | Monitoramento de rede (agendamento + alertas + notificações), conflitos de IP/MAC e diagnóstico (ping/MTR/portas/DNS/RDAP) | aceito |
