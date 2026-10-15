# HookRelay developer commands. Everything runs inside Docker; no local PHP or Node needed.
COMPOSE ?= docker compose

.PHONY: up down test audit seed logs build ps lint fresh

up: ## Build and start the whole stack
	$(COMPOSE) up -d --build

down: ## Stop the stack (keeps data volumes; use `docker compose down -v` to wipe them)
	$(COMPOSE) down

build:
	$(COMPOSE) build

ps:
	$(COMPOSE) ps

seed: ## Create demo sources, destinations and connections (idempotent)
	$(COMPOSE) exec -T api php artisan db:seed --force

test: ## Run the Pest suite (hookrelay_test database; stack must be up) and the dashboard tests
	$(COMPOSE) exec -T api php artisan test
	cd dashboard && npm test

audit: ## Dependency audits (composer + npm, failing on high/critical)
	$(COMPOSE) exec -T api composer audit --no-interaction
	cd dashboard && npm audit --audit-level=high

lint: ## Pint (PHP) and ESLint (TypeScript)
	$(COMPOSE) exec -T api ./vendor/bin/pint --test
	cd dashboard && npm run lint

logs: ## Follow logs for every service
	$(COMPOSE) logs -f --tail=100

fresh: ## Drop and re-run all migrations, then seed (destroys data)
	$(COMPOSE) exec -T api php artisan migrate:fresh --force --seed
