# ADR-0007: Frontend Vue 3 com build estático versionado no repositório

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

A interface é uma SPA (Vue 3 + Vite + TypeScript). O alvo de deploy é um CT LXC enxuto
(ADR-0006), e o objetivo é **não** instalar Node.js/npm em produção: menos pacotes, menos
superfície de ataque e nenhum acesso ao registry npm a partir do CT (que pode estar atrás de
inspeção SSL).

## Decisão

- Código em `frontend/`, build em `frontend/dist/` **versionado no git**.
- nginx serve `/app/` direto do disco (`alias`), com fallback da SPA para `index.html`;
  `/app/_static/*` (nomes com hash; `build.assetsDir`, para não colidir com a rota SPA `/app/assets/:uuid`) com `Cache-Control: immutable` de 1 ano.
- `GET /` redireciona para `/app/`; `POST /` continua sendo o endpoint do GLPI Agent.
- A SPA consome apenas `/api/ui/*` (read models SQL dedicados à interface, em `src/Ui/`)
  com o mesmo token Bearer da API.
- O CI recompila o frontend e **falha se `frontend/dist` estiver desatualizado** em relação ao código.

## Alternativas consideradas

| Alternativa | Por que não |
|---|---|
| Build no CT (`npm ci && npm run build` no install.sh) | exige Node no CT e acesso ao registry npm |
| Artefato de release (GitHub Releases) baixado no deploy | mais um passo/credencial; o `git pull` deixa de ser o deploy completo |
| Twig server-side (sem SPA) | interface menos fluida; o projeto já expõe API para integrações |

## Consequências

- Todo PR que muda `frontend/src` precisa incluir o `dist` recompilado (`npm run build`).
- Diffs do `dist` são ruído na revisão — revisar só `frontend/src`.
- Fase 2 (login OIDC) muda só o `lib/api.ts`; o token estático em `localStorage` é aceitável
  apenas em rede interna de laboratório.
