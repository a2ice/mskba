COMPOSE := docker compose

.PHONY: help build up dev demo down restart ps logs app-logs db-logs shell node-shell db-shell artisan composer npm migrate reset-db reset-db-seed clear fresh test

help:
	@printf "%s\n" \
	"make build      - build containers" \
	"make up         - start all services" \
	"make dev        - start development environment" \
	"make demo       - start demo environment" \
	"make down       - stop all services" \
	"make restart    - restart all services" \
	"make ps         - show service status" \
	"make logs       - show logs for all services" \
	"make app-logs   - show logs for app service" \
	"make db-logs    - show logs for db service" \
	"make shell      - open shell in app container" \
	"make node-shell - open shell in node container" \
	"make db-shell   - open psql shell in db container" \
	"make artisan    - run artisan, pass CMD='route:list'" \
	"make composer   - run composer, pass CMD='install'" \
	"make npm        - run npm, pass CMD='run build'" \
	"make migrate    - run Laravel migrations" \
	"make reset-db   - drop all tables and re-run migrations" \
	"make reset-db-seed - drop all tables, re-run migrations and seed" \
	"make clear      - clear Laravel caches (optimize:clear)" \
	"make fresh      - rebuild and start from scratch" \
	"make test       - run Laravel tests"

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up --build

dev:
	$(COMPOSE) up --build

demo:
	$(COMPOSE) up --build

down:
	$(COMPOSE) down

restart:
	$(COMPOSE) down
	$(COMPOSE) up --build

ps:
	$(COMPOSE) ps

logs:
	$(COMPOSE) logs -f

app-logs:
	$(COMPOSE) logs -f app

db-logs:
	$(COMPOSE) logs -f db

shell:
	$(COMPOSE) exec app sh

node-shell:
	$(COMPOSE) exec node sh

db-shell:
	$(COMPOSE) exec db psql -U dev -d mskba

artisan:
	$(COMPOSE) exec app php artisan $(CMD)

composer:
	$(COMPOSE) exec app composer $(CMD)

npm:
	$(COMPOSE) exec node npm $(CMD)

migrate:
	$(COMPOSE) exec app php artisan migrate

reset-db:
	$(COMPOSE) exec app php artisan migrate:fresh --force

reset-db-seed:
	$(COMPOSE) exec app php artisan migrate:fresh --seed --force

clear:
	$(COMPOSE) exec app php artisan optimize:clear

fresh:
	$(COMPOSE) down -v
	$(COMPOSE) up --build

test:
	$(COMPOSE) exec app php artisan test
