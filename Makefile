.DEFAULT_GOAL := help

.PHONY: help setup build lint test zip clean release

help: ## Show this help message
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}' | \
		sort

setup: ## Install all PHP and Node dependencies
	composer install
	npm install

build: ## Compile JS/CSS for production
	npm run build:production

lint: ## Run all linters (JS, CSS, PHP)
	npm run lint
	npm run lint:php

test: ## Run PHP unit tests
	npm run test:php

zip: ## Build a distributable release zip
	npm run zip

clean: ## Remove build output and release zips
	npm run clean

release: clean build zip ## Full release: clean → build → zip
