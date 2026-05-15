COMPOSE := docker compose
COMPOSE_DEV := $(COMPOSE) -f docker-compose.yml -f docker-compose.dev.yml

.PHONY: help build up dev prod down restart ps logs app-logs db-logs shell node-shell db-shell artisan composer npm migrate reset-db reset-db-seed clear fresh test config dev-config prod-up prod-down

help:
	@printf "%s\n" \
	"make build      - build base containers" \
	"make up         - start base environment in foreground" \
	"make dev        - start development environment with dev services" \
	"make prod-up    - start base environment in detached mode" \
	"make prod-down  - stop production-like base environment" \
	"make down       - stop development environment" \
	"make restart    - restart development environment" \
	"make ps         - show service status" \
	"make logs       - show logs for development environment" \
	"make app-logs   - show logs for phpfpm service" \
	"make db-logs    - show logs for db service" \
	"make shell      - open shell in phpfpm container" \
	"make node-shell - open shell in node container" \
	"make db-shell   - open psql shell in db container" \
	"make artisan    - run artisan, pass CMD='route:list'" \
	"make composer   - run composer, pass CMD='install'" \
	"make npm        - run npm, pass CMD='run build'" \
	"make migrate    - run Laravel migrations" \
	"make reset-db   - drop all tables and re-run migrations" \
	"make reset-db-seed - drop all tables, re-run migrations and seed" \
	"make clear      - clear Laravel caches (optimize:clear)" \
	"make fresh      - rebuild development environment from scratch" \
	"make test       - run Laravel tests in phpfpm container" \
	"make config     - show base docker config" \
	"make dev-config - show merged development docker config"

build:
	$(COMPOSE) build

up:
	$(COMPOSE) up --build

dev:
	$(COMPOSE_DEV) up --build

prod:
	$(COMPOSE) up --build -d

prod-up:
	$(COMPOSE) up --build -d

prod-down:
	$(COMPOSE) down

down:
	$(COMPOSE_DEV) down

restart:
	$(COMPOSE_DEV) down
	$(COMPOSE_DEV) up --build

ps:
	$(COMPOSE_DEV) ps

logs:
	$(COMPOSE_DEV) logs -f

app-logs:
	$(COMPOSE_DEV) logs -f phpfpm

db-logs:
	$(COMPOSE_DEV) logs -f db

shell:
	$(COMPOSE_DEV) exec phpfpm sh

node-shell:
	$(COMPOSE_DEV) exec node sh

db-shell:
	$(COMPOSE_DEV) exec db psql -U $${DB_USERNAME:-dev} -d $${DB_DATABASE:-mskbanew}

artisan:
	$(COMPOSE_DEV) exec phpfpm php artisan $(CMD)

composer:
	$(COMPOSE_DEV) exec phpfpm composer $(CMD)

npm:
	$(COMPOSE_DEV) exec node npm $(CMD)

migrate:
	$(COMPOSE_DEV) exec phpfpm php artisan migrate

reset-db:
	$(COMPOSE_DEV) exec phpfpm php artisan migrate:fresh --force

reset-db-seed:
	$(COMPOSE_DEV) exec phpfpm php artisan migrate:fresh --seed --force

clear:
	$(COMPOSE_DEV) exec phpfpm php artisan optimize:clear

fresh:
	$(COMPOSE_DEV) down -v
	$(COMPOSE_DEV) up --build

test:
	$(COMPOSE_DEV) exec phpfpm php artisan test

config:
	$(COMPOSE) config

dev-config:
	$(COMPOSE_DEV) config
