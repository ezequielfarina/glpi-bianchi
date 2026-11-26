# ADR-0001: Reescrever em vez de fazer fork e poda do GLPI

- **Status:** aceito
- **Data:** 2026-11-26

## Contexto

O objetivo é ter **só** o gerenciamento de inventário do GLPI, sem helpdesk. Análise do
GLPI 12 (branch `12.0/bugfixes`):

- 447 tabelas em `install/mysql/glpi-empty.sql` e 619 classes em `src/`, das quais cerca de 134
  pertencem a ITIL, SLA, Knowbase ou Project;
- o `CommonDBTM.php` (classe base de **todos** os objetos, ativos incluídos) referencia
  diretamente `Ticket` e `CommonITILObject`;
- a parte de inventário (`src/Glpi/Inventory/`, 33 handlers em `Asset/`) é bem isolada e
  segue um formato padronizado e público (`glpi-project/inventory_format`, MIT).

## Decisão

Projeto novo, escrito do zero, **compatível com o protocolo do GLPI Agent** e com o
formato oficial de inventário. Do GLPI levamos apenas os conceitos (matching, campos travados,
inventário parcial, histórico por campo) e o recorte por seções.

## Alternativas consideradas

| Alternativa | Prós | Contras |
|---|---|---|
| Fork e remoção do ITIL | começa funcionando | acoplamento no núcleo, conflito em todo merge do upstream, herda dívida técnica e o modelo "uma tabela por tipo" |
| Plugin GLPI que esconde o helpdesk | zero manutenção de núcleo | continua sendo o GLPI inteiro (peso, superfície de ataque, upgrades) |
| **Reescrita compatível com o agente** | modelo de dados limpo, stack atual, sem bagagem | mais trabalho inicial; paridade de funções é gradual |

## Consequências

- O GLPI Agent funciona sem alteração: dá para migrar máquina a máquina, ou mandar
  para os dois servidores em paralelo durante a transição.
- Licença GPL-3.0-or-later, para poder portar trechos do GLPI quando fizer sentido.
- Funções como SNMP, ESX, licenças e financeiro entram por fases (ver roadmap no README).
