# Frontend (em construção)

Interface web do GLPI Bianchi. **Próxima entrega.**

| Item | Escolha |
|---|---|
| Framework | Vue 3 (Composition API, `<script setup>`) + TypeScript strict |
| Build | Vite → `frontend/dist` (estático, servido pelo nginx do CT em `/app/`) |
| UI | dashboard NOC, tema dark/light, layout responsivo |
| Dados | apenas a API `/api` (JSON), tipos gerados do OpenAPI |

Telas planejadas: visão geral (KPIs e saúde da ingestão), ativos (busca e filtros), detalhe do
ativo (hardware, software, rede, histórico), agentes e inventários recebidos.
