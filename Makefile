.PHONY: help up down restart build shell artisan composer test npm migrate seed playwright-restart logs

# Default target
help:
	@echo "Available commands:"
	@echo "  make up                - Start all containers in background"
	@echo "  make down              - Stop and remove all containers"
	@echo "  make restart           - Restart all containers"
	@echo "  make build             - Build or rebuild services"
	@echo "  make logs              - View output from containers"
	@echo "  make shell             - Enter the PHP app container shell"
	@echo "  make artisan cmd=...   - Run an artisan command (e.g., make artisan cmd='route:list')"
	@echo "  make composer cmd=...  - Run a composer command (e.g., make composer cmd='install')"
	@echo "  make test              - Run PHPUnit tests"
	@echo "  make npm cmd=...       - Run an npm command (e.g., make npm cmd='run dev')"
	@echo "  make migrate           - Run database migrations"
	@echo "  make seed              - Run database seeders"
	@echo "  make playwright-restart- Restart the playwright scraper container"

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

build:
	docker compose build

logs:
	docker compose logs -f

shell:
	docker compose exec app sh

artisan:
	docker compose exec app php artisan $(cmd)

composer:
	docker compose exec app composer $(cmd)

test:
	docker compose exec app php artisan test

npm:
	docker compose run --rm node npm $(cmd)

migrate:
	docker compose exec app php artisan migrate

seed:
	docker compose exec app php artisan db:seed

playwright-restart:
	docker compose restart playwright

bot-restart:
	docker compose restart telegram-bot
