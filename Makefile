.DEFAULT_GOAL := help

ENV ?= dev

ifeq ($(ENV),prod)
DOCKER_COMPOSE := docker compose -f compose.yaml -f compose.prod.yaml
ARTISAN_FORCE := --force
NPM := $(DOCKER_COMPOSE) --profile build run --rm node npm
else ifeq ($(ENV),dev)
DOCKER_COMPOSE := docker compose -f compose.yaml -f compose.override.yaml
ARTISAN_FORCE :=
NPM := npm
else
$(error ENV must be dev or prod)
endif

PHP := $(DOCKER_COMPOSE) exec phpfpm php
COMPOSER := $(DOCKER_COMPOSE) exec phpfpm composer
ARTISAN := $(PHP) artisan
SR_ROLE := $(word 2,$(MAKECMDGOALS))
SR_LOGIN := $(word 3,$(MAKECMDGOALS))

.PHONY: help install update dev serve vite build test lint format migrate fresh fresh-seed seed acceptance-seed tournament-lab-fresh cache-clear optimize-clear queue queue-restart logs shell artisan npm sr up rebuild down restart ps config db-up db-down db-restart db-logs module delete-module

help:
	@echo "Available commands:"
	@echo "  make ENV=dev|prod <command>  Select compose environment (default: dev)"
	@echo "  make up                     Build and start Docker stack"
	@echo "  make rebuild                Rebuild and recreate Docker stack"
	@echo "  make down                   Stop Docker stack"
	@echo "  make restart                Restart Docker stack"
	@echo "  make ps                     Show Docker services"
	@echo "  make config                 Render Docker Compose config"
	@echo "  make install                Install dependencies, prepare app"
	@echo "  make update                 Update Composer and npm dependencies"
	@echo "  make vite                   Run Vite dev server"
	@echo "  make build                  Build frontend assets"
	@echo "  make test                   Run tests"
	@echo "  make lint                   Check code style with Pint"
	@echo "  make format                 Fix code style with Pint"
	@echo "  make migrate                Run database migrations"
	@echo "  make fresh                  Recreate database"
	@echo "  make fresh-seed             Recreate database and seed"
	@echo "  make seed                   Run database seeders"
	@echo "  make acceptance-seed        Seed local standalone/training/tournament scenarios"
	@echo "  make tournament-lab-fresh   Recreate DB with teams, players and venues only"
	@echo "  make cache-clear            Clear Laravel caches"
	@echo "  make optimize-clear         Clear cached bootstrap files"
	@echo "  make queue                  Tail queue worker logs"
	@echo "  make queue-restart          Restart queue worker container"
	@echo "  make logs                   Tail Laravel logs with Pail"
	@echo "  make shell                  Open Tinker"
	@echo "  make artisan CMD='...'      Run artisan command"
	@echo "  make npm CMD='...'          Run npm command"
	@echo "  make sr <role> <login>      Set user system role by login"
	@echo "  make db-up                  Start database service"
	@echo "  make db-down                Stop Docker stack"
	@echo "  make db-restart             Restart PostgreSQL"
	@echo "  make db-logs                Tail PostgreSQL logs"
	@echo "  make module name=... Create bounded context module"
	@echo "  make module name=... model=1 Create module with main model"
	@echo "  make module name=... model=Account Create module with named model"
	@echo "  make module name=... model=Account migration=1 Create model with migration"
	@echo "  make module name=... force=1 Update existing module"
	@echo "  make delete-module name=... force=1 Delete bounded context module"

install:
	$(DOCKER_COMPOSE) up -d --build
	$(COMPOSER) install
	@test -f .env || cp .env.example .env
	$(ARTISAN) key:generate
	$(ARTISAN) storage:link
	$(ARTISAN) migrate
	$(NPM) install
	$(NPM) run build

update:
	$(COMPOSER) update
	$(NPM) update

dev: up vite

serve: up

vite:
	$(NPM) run dev

build:
	$(NPM) run build

test:
	$(COMPOSER) test

lint:
	$(DOCKER_COMPOSE) exec phpfpm ./vendor/bin/pint --test

format:
	$(DOCKER_COMPOSE) exec phpfpm ./vendor/bin/pint

migrate:
	$(ARTISAN) migrate $(ARTISAN_FORCE)

fresh:
	$(ARTISAN) migrate:fresh $(ARTISAN_FORCE)
	$(ARTISAN) cache:clear
	$(DOCKER_COMPOSE) restart queue

fresh-seed:
	$(ARTISAN) migrate:fresh --seed $(ARTISAN_FORCE)
	$(ARTISAN) cache:clear
	$(DOCKER_COMPOSE) restart queue

seed:
	$(ARTISAN) db:seed

acceptance-seed:
	$(ARTISAN) db:seed --class=TournamentAcceptanceSeeder

tournament-lab-fresh:
	$(ARTISAN) migrate:fresh $(ARTISAN_FORCE)
	$(ARTISAN) db:seed --class=DatabaseSeeder $(ARTISAN_FORCE)
	$(ARTISAN) db:seed --class=TournamentLabSeeder $(ARTISAN_FORCE)
	$(ARTISAN) cache:clear
	$(DOCKER_COMPOSE) restart queue

cache-clear:
	$(ARTISAN) cache:clear
	$(ARTISAN) config:clear
	$(ARTISAN) route:clear
	$(ARTISAN) view:clear

optimize-clear:
	$(ARTISAN) optimize:clear

queue:
	$(DOCKER_COMPOSE) logs -f queue

queue-restart:
	$(DOCKER_COMPOSE) restart queue

logs:
	$(ARTISAN) pail

shell:
	$(ARTISAN) tinker

artisan:
ifndef CMD
	$(error Usage: make artisan CMD='route:list')
endif
	$(ARTISAN) $(CMD)

npm:
ifndef CMD
	$(error Usage: make npm CMD='run build')
endif
	$(NPM) $(CMD)

sr:
	@if [ -z "$(SR_ROLE)" ] || [ -z "$(SR_LOGIN)" ]; then \
		echo "Usage: make sr <role> <login>"; \
		echo "Example: make sr superadmin user_login"; \
		exit 2; \
	fi
	$(ARTISAN) identity:set-system-role $(SR_ROLE) $(SR_LOGIN)

up:
	$(DOCKER_COMPOSE) up -d --build

rebuild:
	$(DOCKER_COMPOSE) up -d --build --force-recreate

down:
	$(DOCKER_COMPOSE) down

restart:
	$(DOCKER_COMPOSE) restart

ps:
	$(DOCKER_COMPOSE) ps

config:
	$(DOCKER_COMPOSE) config

db-up:
	$(DOCKER_COMPOSE) up -d db

db-down:
	$(DOCKER_COMPOSE) down

db-restart:
	$(DOCKER_COMPOSE) restart db

db-logs:
	$(DOCKER_COMPOSE) logs -f db

module:
ifndef name
	$(error Usage: make module name=Billing)
endif
	$(ARTISAN) make:module $(name) $(if $(model),$(if $(filter 1 true yes,$(model)),--model,--model=$(model)),) $(if $(migration),--migration,) $(if $(force),--force,)

delete-module:
ifndef name
	$(error Usage: make delete-module name=Billing force=1)
endif
	$(ARTISAN) delete:module $(name) $(if $(force),--force,)

%:
	@if [ "$(firstword $(MAKECMDGOALS))" = "sr" ]; then \
		:; \
	else \
		echo "Unknown make target [$@]."; \
		exit 2; \
	fi
