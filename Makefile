# GLPI Bianchi — atalhos de desenvolvimento e operação (execução nativa, sem Docker)
# `make` ou `make help` lista os alvos.
#
# Rodar dentro do CT (ou em qualquer máquina com PHP 8.3+, PostgreSQL e Valkey/Redis).

PHP     ?= php
CONSOLE ?= $(PHP) bin/console
COMPOSER ?= composer
HTTP_URL ?= http://localhost/

.DEFAULT_GOAL := help
.PHONY: help install update migrate migration diff db-validate db test test-db \
        stan rector lint fix ci worker workers-restart status logs agent-sample cache

help: ## Lista os alvos disponíveis
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n",$$1,$$2}'

## —— Projeto ———————————————————————————————————————————————
install: ## composer install + migrations (primeiro setup / dev)
	$(COMPOSER) install --no-interaction
	$(MAKE) migrate

update: ## Deploy de nova versão: git pull, deps, migrations, cache, restart workers
	git pull --ff-only
	$(COMPOSER) install --no-dev --no-interaction --optimize-autoloader --classmap-authoritative
	$(MAKE) migrate
	$(CONSOLE) cache:clear --env=prod
	systemctl reload php8.4-fpm
	$(MAKE) workers-restart

cache: ## Limpa o cache do Symfony
	$(CONSOLE) cache:clear

## —— Banco —————————————————————————————————————————————————
migrate: ## Aplica migrations pendentes
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --allow-no-migration

migration: ## Gera migration vazia (SQL manual)
	$(CONSOLE) doctrine:migrations:generate

diff: ## Gera migration a partir da diferença entidades x banco
	$(CONSOLE) doctrine:migrations:diff --formatted

db-validate: ## Valida mapeamento Doctrine x schema do banco
	$(CONSOLE) doctrine:schema:validate

db: ## psql no banco da aplicação
	psql "$$(grep -E '^DATABASE_URL' .env.local 2>/dev/null | cut -d= -f2- | tr -d '"' | sed 's/?.*//')"

## —— Qualidade —————————————————————————————————————————————
test-db: ## Cria e migra o banco de testes (bianchi_test)
	$(CONSOLE) doctrine:database:create --if-not-exists --env=test
	$(CONSOLE) doctrine:migrations:migrate --no-interaction --env=test

test: test-db ## PHPUnit
	$(PHP) vendor/bin/phpunit

stan: ## PHPStan
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

rector: ## Rector (dry-run)
	$(PHP) vendor/bin/rector process --dry-run

lint: ## php-cs-fixer (dry-run) + PHPStan + Rector
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(MAKE) stan rector

fix: ## Aplica php-cs-fixer e Rector
	$(PHP) vendor/bin/php-cs-fixer fix
	$(PHP) vendor/bin/rector process

ci: lint db-validate test ## Pipeline local equivalente ao GitHub Actions

## —— Operação (systemd) ————————————————————————————————————
worker: ## Roda um consumidor em foreground (debug)
	$(CONSOLE) messenger:consume inventory -vv --limit=10

workers-restart: ## Reinicia todos os workers (após deploy)
	$(CONSOLE) messenger:stop-workers
	systemctl restart 'bianchi-worker@*' bianchi-scan-worker

status: ## Status dos serviços do CT
	systemctl --no-pager status nginx php8.4-fpm postgresql valkey-server 'bianchi-worker@*' bianchi-scan-worker bianchi-scan-scheduler.timer | grep -E '●|Active:'

logs: ## Segue os logs dos workers
	journalctl -f -u 'bianchi-worker@*' -u bianchi-scan-worker -u bianchi-scan-scheduler

## —— Inventário ————————————————————————————————————————————
agent-sample: ## Envia o inventário de exemplo como se fosse o GLPI Agent
	./tools/send-inventory.sh tests/Fixtures/inventory/computer-linux.json $(HTTP_URL)
