# Contribuindo

## Fluxo

1. Crie uma branch a partir da `main`: `feat/<assunto>`, `fix/<assunto>`, `docs/<assunto>`, `db/<assunto>`.
2. Commits no padrão [Conventional Commits](https://www.conventionalcommits.org/), em inglês:
   `feat(inventory): import graphic cards section`, `fix(db): add missing index on asset.location_id`.
3. Rode `make ci` localmente antes do push.
4. Abra o PR descrevendo o **porquê**. Mudança de arquitetura vem acompanhada de ADR.
5. CI verde + revisão → merge com *squash*.

## Padrões de código

| Ferramenta | Configuração | Comando |
|---|---|---|
| php-cs-fixer | `.php-cs-fixer.dist.php` (`@Symfony` + risky) | `make fix` |
| PHPStan | `phpstan.dist.neon` (nível **max** + Symfony + Doctrine) | `make stan` |
| Rector | `rector.php` (PHP 8.3, Symfony, Doctrine) | `make rector` |
| PHPUnit | `phpunit.dist.xml` (falha em deprecation/notice/warning) | `make test` |

## Testes

- `tests/Unit` — sem kernel, sem banco. Rápidos.
- `tests/Integration` — `WebTestCase`/`KernelTestCase` contra o banco `bianchi_test`.
  Cada teste roda numa transação revertida (dama/doctrine-test-bundle).
- Fixtures de inventário: `tests/Fixtures/inventory/*.json`. Para capturar um inventário
  real de uma máquina: `glpi-inventory --json > maquina.json` (remova dados sensíveis antes).

## Versionamento

[SemVer](https://semver.org/). Mudanças registradas em [CHANGELOG.md](CHANGELOG.md).
