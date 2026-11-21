.PHONY: help deps build dev l10n lint lint-php lint-js format check-types typecheck ocp stan test test-php test-unit-js \
	openapi test-contract contract-fixtures check-contract-fixtures ci-local clean package check-tarball \
	link unlink dev-server dev-up dev-down dev-reset dev-occ dev-logs dev-shell e2e-up test-e2e e2e-down

app_name := oco_synaplan
# The BW-Tech ownCloud.online checkout and its tag (README "Development").
OCO_SERVER_PATH ?= ../owncloud.online
OCO_TAG ?= v11.0.10
OCO_REPO := https://github.com/BWTECH-github/owncloud.online.git
# Override with: make link OC_APPS=/path/to/owncloud.online/apps
OC_APPS ?= $(OCO_SERVER_PATH)/apps
build_dir := release-work/build

# The Synaplan the app is tested against: the image pinned in CI, and a
# metadist/synaplan checkout for its test stack and the contract fixtures.
SYNAPLAN_DIR ?= ../synaplan
export APP_IMAGE ?= ghcr.io/metadist/synaplan@$(shell grep -oE 'SYNAPLAN_IMAGE_DIGEST:[[:space:]]+sha256:[a-f0-9]+' .github/workflows/ci.yml | awk '{print $$2}')
export SYNAPLAN_DIR

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-24s\033[0m %s\n", $$1, $$2}'

## Dependencies

deps: ## Install PHP + JS dependencies
	composer install
	npm ci

## Build

build: ## Build the frontend IIFE bundles into js/
	npm run build

dev: ## Rebuild the frontend on change (watch)
	npm run dev

l10n: ## Write l10n/<locale>.js from l10n/<locale>.json after editing translations
	node scripts/l10n-js.mjs

## Quality

lint: lint-php lint-js ## Run all linters

lint-php: ## PHP code style check (php-cs-fixer, PSR-12)
	composer run lint

lint-js: ## Frontend lint (ESLint) and format check (Prettier)
	npm run lint
	npm run format:check

format: ## Fix the PHP code style and format the frontend
	composer run lint:fix
	npm run format

check-types typecheck: ## Type-check the frontend and the tests (vue-tsc)
	npm run check-types

ocp: ## Fetch the OCP interfaces of OCO_TAG for PHPUnit and PHPStan (.cache/ocp)
	scripts/ocp.sh

stan: ocp ## PHPStan (level in phpstan.neon, no baseline)
	composer run stan

test: test-php ## Run the PHPUnit unit tests

test-php: ocp ## Run the PHPUnit unit tests
	composer run test

test-unit-js: ## Run the Vitest unit tests
	npm run test:unit

openapi: ## Dump the pinned Synaplan's OpenAPI spec and routes (SYNAPLAN_SRC_CONTAINER=synaplan-app-test: the running test stack)
	scripts/synaplan-openapi.sh

test-contract: ocp openapi ## Check every call the app makes against the pinned Synaplan
	composer run test:contract

contract-fixtures: ## Vendor the pinned Synaplan's contract fixtures from SYNAPLAN_DIR (never edit them by hand)
	scripts/vendor-contract.sh

check-contract-fixtures: ## Check the vendored contract fixtures are the pinned Synaplan's, byte for byte
	scripts/vendor-contract.sh --check

ci-local: lint stan test test-contract check-contract-fixtures typecheck test-unit-js package check-tarball ## Every CI job except e2e — run before each commit

## Dev install into ownCloud.online

link: ## Symlink this app into an ownCloud.online apps dir (OC_APPS=...)
	ln -sfn $(CURDIR) $(OC_APPS)/$(app_name)
	@echo "Linked into $(OC_APPS)/$(app_name) — enable with: occ app:enable $(app_name)"

unlink: ## Remove the symlink from the ownCloud.online apps dir
	rm -f $(OC_APPS)/$(app_name)

## Local ownCloud.online stack (Docker)
# OCO_SERVER_PATH is the BW-Tech checkout, OCO_TAG the version it must be at.
# A version change needs fresh volumes: make dev-server dev-reset dev-up.

COMPOSE_DEV := docker compose -f docker-compose.dev.yml
OCO_EXEC := $(COMPOSE_DEV) exec -T -u www-data owncloud
export OCO_SERVER_PATH OCO_TAG SYNAPLAN_URL_LOCAL

# The gitignored copy follows the example: created when missing, and refreshed
# when the example is newer, with the old copy kept as docker-compose.dev.yml.bak.
docker-compose.dev.yml: docker-compose.dev.yml.example
	@if [ -f $@ ]; then \
		cp $@ $@.bak; \
		echo "docker-compose.dev.yml was older than the example: refreshed, your copy is docker-compose.dev.yml.bak."; \
	fi
	cp $< $@

dev-server: ## Clone the BW-Tech checkout into OCO_SERVER_PATH, or check out OCO_TAG there
	@if [ ! -d $(OCO_SERVER_PATH)/.git ]; then \
		git clone --depth 1 --branch $(OCO_TAG) $(OCO_REPO) $(OCO_SERVER_PATH); \
	else \
		git -C $(OCO_SERVER_PATH) fetch --depth 1 origin tag $(OCO_TAG) && \
		git -C $(OCO_SERVER_PATH) checkout --quiet $(OCO_TAG); \
	fi
	@git -C $(OCO_SERVER_PATH) describe --tags

dev-up: docker-compose.dev.yml ## Start ownCloud.online on :8083 with MariaDB, cron and the test people
	@test -f $(OCO_SERVER_PATH)/version.php || { echo "No checkout at $(OCO_SERVER_PATH): run 'make dev-server'."; exit 1; }
	@if [ ! -f js/files-init.js ]; then \
		echo "Building the frontend (js/ is missing)…"; \
		test -d node_modules || npm ci; \
		$(MAKE) build; \
	fi
	$(COMPOSE_DEV) up -d --build --wait
	$(OCO_EXEC) sh /var/www/html/apps/$(app_name)/docker/dev-seed.sh
	@curl -fsS http://localhost:8083/status.php; echo
	@$(OCO_EXEC) php occ app:list $(app_name) 2>/dev/null | sed -n '/^Enabled:/,/^Disabled:/p' | grep -q ' $(app_name):' \
		&& echo "$(app_name) is enabled" || { echo "$(app_name) is not enabled"; exit 1; }
	@echo ""
	@echo "ownCloud.online: http://localhost:8083  (admin / admin; ada, bob, carol / <name>-dev-pass)"

dev-down: docker-compose.dev.yml ## Stop local ownCloud.online (keeps the volumes)
	$(COMPOSE_DEV) down

dev-reset: docker-compose.dev.yml ## Remove the config, data and database volumes — destroys local data
	$(COMPOSE_DEV) down -v

dev-occ: docker-compose.dev.yml ## Run occ in the dev container, e.g. make dev-occ CMD="user:list"
	$(OCO_EXEC) php occ $(CMD)

dev-logs: docker-compose.dev.yml ## Follow the ownCloud.online container logs
	$(COMPOSE_DEV) logs -f owncloud owncloud-cron

dev-shell: docker-compose.dev.yml ## Open a shell in the ownCloud.online container as www-data
	$(COMPOSE_DEV) exec -u www-data owncloud bash

## End-to-end journeys (Playwright)
# The Synaplan test stack of SYNAPLAN_DIR runs the pinned image (APP_IMAGE)
# on :8001; the journeys run on the dev stack, which reaches it on the
# Docker host.

COMPOSE_E2E_SYNAPLAN := docker compose -p synaplan-owncloud-e2e-synaplan \
	-f $(SYNAPLAN_DIR)/docker-compose.test.yml \
	-f $(SYNAPLAN_DIR)/docker-compose.test.ci.yml \
	-f tests/e2e/synaplan-test-stack.yml

e2e-up: build ## Start the Synaplan test stack on :8001 and ownCloud.online on :8083
	@test -f $(SYNAPLAN_DIR)/docker-compose.test.yml || { echo "No Synaplan checkout at $(SYNAPLAN_DIR) (README, Development)."; exit 1; }
	docker image inspect $(APP_IMAGE) >/dev/null 2>&1 || docker pull -q $(APP_IMAGE)
	$(COMPOSE_E2E_SYNAPLAN) up -d --wait
	$(MAKE) dev-up SYNAPLAN_URL_LOCAL=http://host.docker.internal:8001

test-e2e: ## Run the e2e journeys against the stacks of e2e-up
	npx playwright test

e2e-down: ## Remove the Synaplan test stack and reset the dev stack — destroys both stacks' data
	$(COMPOSE_E2E_SYNAPLAN) down -v
	$(MAKE) dev-reset

## Release

clean: ## Remove build artifacts
	rm -rf js/*.js node_modules vendor $(build_dir)
	rm -f $(app_name).tar.gz

package: build ## Create an installable app tarball
	rm -rf $(build_dir)
	mkdir -p $(build_dir)/$(app_name)
	cp -r appinfo lib templates img js l10n public $(build_dir)/$(app_name)/
	cp CHANGELOG.md LICENSE README.md $(build_dir)/$(app_name)/ 2>/dev/null || true
	cd $(build_dir) && tar -czf ../../$(app_name).tar.gz $(app_name)
	rm -rf $(build_dir)
	@echo ""
	@echo "Created: $(app_name).tar.gz"
	@tar -tzf $(app_name).tar.gz | head -12
	@echo "..."

check-tarball: ## Check the tarball of make package holds the app and nothing from development
	scripts/check-tarball.sh $(app_name).tar.gz
