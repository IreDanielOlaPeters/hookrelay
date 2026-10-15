# HookRelay developer commands. Everything runs inside Docker; no local PHP or Node needed.
COMPOSE ?= docker compose

.PHONY: up down test seed logs build ps lint fresh

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

test: ## Run the Pest suite against the hookrelay_test database (stack must be up)
	$(COMPOSE) exec -T api php artisan test

lint: ## Pint (PHP) and ESLint (TypeScript)
	$(COMPOSE) exec -T api ./vendor/bin/pint --test
	cd dashboard && npm run lint

logs: ## Follow logs for every service
	$(COMPOSE) logs -f --tail=100

fresh: ## Drop and re-run all migrations, then seed (destroys data)
	$(COMPOSE) exec -T api php artisan migrate:fresh --force --seed
