# ADR-0020: Identidade de MAC e filtro de MAC das redes Wi-Fi do UniFi

- **Status:** aceito
- **Data:** 2026-11-28
- **Decisores:** Ezequiel Farina

## Contexto

O filtro de MAC de cada rede Wi-Fi do UniFi Network (*MAC Address Filter*) guarda só a lista de endereços:
não diz de quem é o MAC, que aparelho é, desde quando nem até quando vale. O controller roda numa VM própria,
gerenciada pelo `unifi.ui.com`. O objetivo é cadastrar a identidade no GLPI Bianchi e mandar o MAC para a
rede escolhida, no site escolhido.

APIs disponíveis (pesquisa de 2026-11):

| API | Escreve filtro? | Autenticação | Observação |
|---|---|---|---|
| Site Manager (`api.ui.com/v1`) | **não** | API key da conta UI | somente leitura; proxy `connector/consoles/{id}` só para consoles UniFi OS |
| Integration API (`/integration/v1`, Network 9/10) | sim (`clientFilteringPolicy` ALLOW/BLOCK, ≤ 512 MACs) | API key | `PUT` exige o **objeto inteiro** do Wi-Fi (inclusive senha); controller self-hosted clássico não aceita API key |
| API clássica (`/api/s/{site}/rest/wlanconf`) | sim (`mac_filter_list/policy/enabled`) | admin local (cookie + CSRF) ou API key no UniFi OS | `PUT` **parcial**: só os campos enviados mudam; funciona de 5.x a 10.x |

## Decisão

1. **API clássica, atualização parcial**: o `PUT` envia apenas `mac_filter_enabled`, `mac_filter_policy` (os
   valores lidos, nunca alterados automaticamente) e `mac_filter_list`. A senha e o resto do Wi-Fi nunca são
   regravados — sem risco de derrubar a rede. Três formas de conexão:
   `local` (admin local; detecta UniFi OS × clássico), `api_key` (UniFi OS / UniFi OS Server) e `cloud`
   (proxy do Site Manager, experimental, para quando o CT não alcança a VM).
2. **Fonte da verdade no GLPI Bianchi** (`mac_identity`, `mac_wlan_assignment`). O painel só acrescenta e retira
   **os MACs que ele gerencia**: o que já estava no UniFi é preservado e aparece como "sem identificação" para
   identificar ou importar.
3. **Aplicação**: ler a lista atual → (atual − removidos do painel) ∪ desejados → `PUT` → ler de novo e
   conferir. Trava por rede (`pg_advisory_xact_lock`) contra corrida entre dois técnicos. Falha vira
   `pending`/`error` com nova tentativa a cada 10 minutos (`app:wifi:sync`), que também revoga acessos vencidos.
4. **Apelido no UniFi** (opcional por controller): grava "Pessoa — aparelho" como nome do cliente e setor/matrícula
   na nota — a identificação também aparece na tela do UniFi.
5. **Segurança**: senha/API key cifradas (SecretBox, contexto `unifi.secret:<id>`); TLS validado pela CA ou por
   **pin da chave pública** (certificado autoassinado do controller sem desligar a verificação); cadastro de
   controller só para administrador; aplicação auditada; o `x_passphrase` que o controller devolve no `GET`
   nunca é guardado nem logado.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Integration API (`PUT` completo) | oficial, tipada | regrava o Wi-Fi inteiro (senha, rádios) a cada MAC; indisponível no controller clássico |
| Só a nuvem (Site Manager) | sem rota até a VM | API somente leitura |
| RADIUS MAC auth (FreeRADIUS + `users` gerado) | escala, log de autenticação | outro serviço para manter; muda o modelo das redes atuais |
| Guardar identidade no nome do cliente do UniFi | sem banco novo | sem validade, sem histórico, sem vínculo com inventário |

## Consequências

- Positivas: quem é cada MAC, em que redes está, até quando; nada no UniFi é apagado sem identidade; senha do Wi-Fi intocada.
- Negativas: filtro por MAC não é autenticação forte (MAC clonável) — para redes corporativas o caminho é 802.1X/RADIUS;
  a API clássica não é documentada oficialmente (pode mudar entre versões — o teste de conexão verifica).
- Revisitar: migrar a escrita para a Integration API se ela ganhar atualização parcial; RADIUS (FreeRADIUS) usando
  `mac_identity` como base, quando as redes forem para WPA2/3-Enterprise.
