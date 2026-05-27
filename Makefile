.DEFAULT_GOAL := help

PHP := php
COMPOSER := composer
NPM := npm
ARTISAN := $(PHP) artisan

.PHONY: help install update dev serve vite build test lint format migrate fresh seed cache-clear optimize-clear queue logs shell module

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
	@echo "  make module name=... Create bounded context module"

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

module:
ifndef name
	$(error Usage: make module name=Billing)
endif
	$(ARTISAN) make:module $(name)
