# ADR-0010: Matriz e filiais como cadastro próprio, com mapa e vínculo por rede

- **Status:** aceito
- **Data:** 2026-11-26
- **Decisores:** Ezequiel Farina

## Contexto

A empresa tem uma matriz e várias filiais. É preciso:

- saber em qual unidade cada ativo está, tanto os manuais (impressoras, coletores, telefones) quanto os que vêm do GLPI Agent;
- guardar o endereço da unidade e mostrá-la no mapa.

O modelo já tinha `organization` (a "Entidade" do GLPI: escopo administrativo e de permissão) e `location` (hierarquia física livre, como prédio, andar e sala). Nenhum dos dois servia bem:

- usar `organization` para filial misturaria permissão com geografia;
- `location` é texto livre hierárquico, sem endereço estruturado nem coordenadas.

Os computadores com agente são dezenas por unidade, e atribuir a filial manualmente a cada um não escala. Cada unidade tem as próprias sub-redes (VLANs por filial), então o IP identifica a unidade.

## Decisão

1. Nova tabela **`branch`**:
   - `kind` é `headquarters` ou `branch`;
   - nome e sigla são únicos;
   - CNPJ;
   - endereço estruturado (CEP, logradouro, número, complemento, bairro, cidade, UF);
   - `latitude`/`longitude` em `NUMERIC(9,6)`, padrão WGS84;
   - `networks` em JSONB (lista de CIDRs);
   - `active`.
2. **`asset.branch_id`** é uma FK opcional com `ON DELETE SET NULL`. Excluir uma unidade não exclui ativos: eles ficam sem filial.
3. **No máximo uma matriz.** A regra fica no `BranchWriter`, sob `LOCK TABLE branch IN SHARE ROW EXCLUSIVE MODE`. Não usei índice parcial: o DBAL compara o predicado `WHERE` normalizado pelo PostgreSQL e o `schema:validate` acusaria diferença eterna.
4. **Vínculo automático por rede** (`BranchResolver`). Ele vale só para ativos **sem filial**, e a escolha manual nunca é sobrescrita:
   - o IP considerado é o IPv4 das portas físicas (sem APIPA) e, na falta dele, o `remote_ip` do agente;
   - é usado o operador `inet <<= cidr`;
   - entre redes sobrepostas vence o prefixo mais longo, então o /24 da filial ganha do /8 corporativo;
   - roda após cada inventário (fora da transação do import, sem invalidá-lo) e ao salvar uma unidade;
   - gera histórico com `actor = 'rede da filial'`.
5. **Mapa no navegador**:
   - Leaflet com tiles do OpenStreetMap, carregado só nas telas de filiais;
   - geocodificação manual pelo Nominatim, CEP pelo ViaCEP, colar link ou coordenadas do Google Maps, ou clicar e arrastar no mapa;
   - botões "Google Maps" e "Rota" abrem o Google Maps.

   O servidor do CT **não** acessa a internet para nada disso.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Filial = `organization` | já existe, hierárquica | mistura escopo de permissão com geografia; sem endereço/coordenadas |
| Filial = nó raiz de `location` | reaproveita a árvore | texto livre; sem CNPJ/endereço; difícil filtrar "por filial" |
| Google Maps JavaScript API | visual familiar | exige chave de API e faturamento, além de chamadas a partir de um domínio autorizado |
| Tabela `branch_network` com coluna `cidr` | índice GiST possível | mais uma entidade; poucas linhas por filial não justificam |
| Atribuir a filial pelo `remote_ip` apenas | simples | atrás de NAT/VPN o IP de origem é o do firewall |

## Consequências

- **Positivas:**
  - filtro e coluna "Filial" na lista de ativos e movimentação em lote;
  - computadores com agente ganham a filial sozinhos;
  - visão em mapa;
  - fotos e documentos por unidade (anexos `owner_type = branch`).
- **Negativas / dívidas assumidas:**
  - tiles, CEP e geocodificação dependem de internet **no navegador**. Com inspeção SSL ou filtro web (FortiGate), libere `tile.openstreetmap.org`, `nominatim.openstreetmap.org` e `viacep.com.br`;
  - a política de uso do OSM limita volume, o que é adequado para uso interno.
- **Revisitar:**
  - redes por VLAN do switch (SNMP, fase 2);
  - servidor de tiles próprio, se o acesso externo for bloqueado;
  - permissão por filial, quando houver login individual (ADR futura).
