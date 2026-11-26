# Frontend

Interface web do GLPI Bianchi — Vue 3 + Vite + TypeScript (strict). Servida pelo nginx do CT em
**`/app/`** a partir do build versionado em `dist/` (sem Node em produção — [ADR-0007](../docs/adr/0007-frontend-build-versionado.md)).

## Telas

| Rota | Tela | Endpoint |
|---|---|---|
| `/app/login` | token de acesso | `GET /api/ui/me` |
| `/app/` | visão geral: KPIs, inventários/hora (24 h), SOs, últimos ativos — atualiza a cada 30 s | `GET /api/ui/overview` |
| `/app/assets` | busca (nome, serial, patrimônio, UUID, **MAC**, **IP**), filtros, ordenação, paginação (estado na URL) | `GET /api/ui/assets` |
| `/app/assets/:uuid` | detalhe: hardware, software, rede (oculta adaptadores virtuais), histórico, inventários + payload JSON | `GET /api/ui/assets/{uuid}` |
| `/app/agents` | agentes, versão, IP de origem, tarefas, último contato, **forçar inventário** | `GET /api/ui/agents`, `POST …/wakeup` |
| `/app/inventories` | payloads recebidos, status, **resultado** (novo/atualizado/sem alteração), erros, JSON bruto | `GET /api/ui/inventories` |
| `/app/assets/new`, `/app/assets/:uuid/edit` | cadastro/edição manual (tipo, patrimônio automático, foto pela câmera, características por tipo) | `POST/PATCH /api/ui/assets`, `POST /api/ui/attachments` |
| `/app/licenses`, `/app/licenses/:id` | licenças, conformidade (usados × comprados × detectados), chave, atribuições, anexos | `/api/ui/licenses…` |
| `/app/contracts`, `/app/contracts/:id` | contratos, vigência/vencimento, custo anual, ativos cobertos, anexos | `/api/ui/contracts…` |
| `/app/suppliers`, `/app/suppliers/:id` | fornecedores, contatos, contratos, documentos | `/api/ui/suppliers…`, `/api/ui/contacts…` |
| `/app/settings` | frequência de inventário, prefixo do patrimônio, porta do agente, tipos/categorias de licença, tipos de contrato | `/api/ui/settings`, `/api/ui/lookups…` |

## Desenvolvimento

```bash
cd frontend
npm ci
VITE_API_TARGET=http://192.168.110.55 npm run dev   # proxy de /api para o CT
npm run build                                        # typecheck + build em dist/ (commitar!)
```

## Estrutura

```
src/
├── main.ts, App.vue, router.ts     # bootstrap, layout, rotas (guarda de autenticação)
├── styles.css                      # tokens de tema (dark padrão / light) e componentes base
├── lib/
│   ├── api.ts                      # cliente /api/ui (Bearer, 401 → login)
│   ├── types.ts                    # contratos (espelham src/Ui/ReadModel/*.php)
│   ├── format.ts                   # datas relativas, bytes, rótulos pt-BR
│   ├── theme.ts, useAsync.ts
├── components/                     # AppShell, AppIcon, KpiCard, StatusPill, LoadState
└── views/                          # uma por tela
```

## Padrões

- Tema escuro (NOC) padrão; claro via botão, salvo no navegador. Contraste ≥ 4.5:1 nos dois.
- Fontes self-hosted (Inter, JetBrains Mono) — nenhuma chamada externa.
- Acessibilidade: navegação por teclado, `aria-sort` nas colunas, `role=tablist` nas abas,
  "pular para o conteúdo", alvos de toque ≥ 40 px.
- Novos campos na tela → primeiro no read model (`src/Ui/ReadModel`), depois em `lib/types.ts`.
