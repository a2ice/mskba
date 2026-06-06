 .DEFAULT_GOAL := help

PHP := docker compose exec phpfpm php
COMPOSER := docker compose exec phpfpm composer
NPM := npm
ARTISAN := $(PHP) artisan
DOCKER_COMPOSE := docker compose

.PHONY: help install update dev serve vite build test lint format migrate fresh fresh-seed seed cache-clear optimize-clear queue queue-restart logs shell up rebuild down restart ps db-up db-down db-restart db-logs module delete-module

help:
	@echo "Available commands:"
	@echo "  make up              Build and start Docker stack"
	@echo "  make rebuild         Rebuild and recreate Docker stack"
	@echo "  make down            Stop Docker stack"
	@echo "  make restart         Restart Docker stack"
	@echo "  make ps              Show Docker services"
	@echo "  make install         Install dependencies, prepare app"
	@echo "  make update          Update Composer and npm dependencies"
	@echo "  make vite            Run Vite dev server"
	@echo "  make build           Build frontend assets"
	@echo "  make test            Run tests"
	@echo "  make lint            Check code style with Pint"
	@echo "  make format          Fix code style with Pint"
	@echo "  make migrate         Run database migrations"
	@echo "  make fresh           Recreate database and seed"
	@echo "  make seed            Run database seeders"
	@echo "  make cache-clear     Clear Laravel caches"
	@echo "  make optimize-clear  Clear cached bootstrap files"
	@echo "  make queue           Tail queue worker logs"
	@echo "  make queue-restart   Restart queue worker container"
	@echo "  make logs            Tail Laravel logs with Pail"
	@echo "  make shell           Open Tinker"
	@echo "  make db-up           Start database service"
	@echo "  make db-down         Stop Docker stack"
	@echo "  make db-restart      Restart PostgreSQL"
	@echo "  make db-logs         Tail PostgreSQL logs"
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
	$(ARTISAN) migrate

fresh:
	$(ARTISAN) migrate:fresh
	$(ARTISAN) cache:clear
	$(DOCKER_COMPOSE) restart queue

fresh-seed:
	$(ARTISAN) migrate:fresh --seed
	$(ARTISAN) cache:clear
	$(DOCKER_COMPOSE) restart queue

seed:
	$(ARTISAN) db:seed

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
