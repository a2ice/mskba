 .DEFAULT_GOAL := help

PHP := php
COMPOSER := composer
NPM := npm
ARTISAN := $(PHP) artisan
DOCKER_COMPOSE := docker compose

.PHONY: help install update dev serve vite build test lint format migrate fresh seed cache-clear optimize-clear queue logs shell up down restart db-up db-down db-restart db-logs module delete-module

help:
	@echo "Available commands:"
	@echo "  make install         Install PHP and Node dependencies, prepare app"
	@echo "  make update          Update Composer and npm dependencies"
	@echo "  make dev             Run Laravel dev stack"
	@echo "  make serve           Run Laravel HTTP server"
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
	@echo "  make queue           Run queue worker"
	@echo "  make logs            Tail Laravel logs with Pail"
	@echo "  make shell           Open Tinker"
	@echo "  make db-up           Start PostgreSQL"
	@echo "  make db-down         Stop PostgreSQL"
	@echo "  make db-restart      Restart PostgreSQL"
	@echo "  make db-logs         Tail PostgreSQL logs"
	@echo "  make module name=... Create bounded context module"
	@echo "  make module name=... model=1 Create module with main model"
	@echo "  make module name=... model=Account Create module with named model"
	@echo "  make module name=... model=Account migration=1 Create model with migration"
	@echo "  make module name=... force=1 Update existing module"
	@echo "  make delete-module name=... force=1 Delete bounded context module"

install:
	$(COMPOSER) install
	@test -f .env || cp .env.example .env
	$(ARTISAN) key:generate
	@test -f database/database.sqlite || touch database/database.sqlite
	$(ARTISAN) migrate
	$(NPM) install
	$(NPM) run build

update:
	$(COMPOSER) update
	$(NPM) update

dev:
	$(COMPOSER) run dev

serve:
	$(ARTISAN) serve --host=127.0.0.1 --port=8000

vite:
	$(NPM) run dev

build:
	$(NPM) run build

test:
	$(COMPOSER) test

lint:
	./vendor/bin/pint --test

format:
	./vendor/bin/pint

migrate:
	$(ARTISAN) migrate

fresh:
	$(ARTISAN) migrate:fresh

fresh-seed:
	$(ARTISAN) migrate:fresh --seed

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
	$(ARTISAN) queue:work

logs:
	$(ARTISAN) pail

shell:
	$(ARTISAN) tinker

up:
	$(DOCKER_COMPOSE) up -d

down:
	$(DOCKER_COMPOSE) down

restart:
	$(DOCKER_COMPOSE) restart

db-up:
	$(DOCKER_COMPOSE) up -d postgres

db-down:
	$(DOCKER_COMPOSE) down

db-restart:
	$(DOCKER_COMPOSE) restart postgres

db-logs:
	$(DOCKER_COMPOSE) logs -f postgres

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
