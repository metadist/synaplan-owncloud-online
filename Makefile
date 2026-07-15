.PHONY: help build dev lint lint-php lint-js check-types deps clean package link unlink

app_name := oco_synaplan
# Override with: make link OC_APPS=/path/to/owncloud/apps
OC_APPS ?= /wwwroot/owncloud.online/apps
build_dir := release-work/build

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

## Dependencies

deps: ## Install PHP + JS dependencies
	composer install
	npm install

## Build

build: ## Build the frontend IIFE bundles into js/
	npm run build

dev: ## Rebuild the frontend on change (watch)
	npm run dev

## Quality

lint: lint-php lint-js ## Run all linters

lint-php: ## PHP code style check (php-cs-fixer, PSR-12)
	composer run lint

lint-js: ## Frontend lint + type check
	npm run check-types

check-types: ## Type-check the frontend (vue-tsc)
	npm run check-types

## Dev install into ownCloud

link: ## Symlink this app into an ownCloud apps dir (OC_APPS=...)
	ln -sfn $(CURDIR) $(OC_APPS)/$(app_name)
	@echo "Linked into $(OC_APPS)/$(app_name) — enable with: occ app:enable $(app_name)"

unlink: ## Remove the symlink from the ownCloud apps dir
	rm -f $(OC_APPS)/$(app_name)

## Release

clean: ## Remove build artifacts
	rm -rf js/*.js node_modules vendor $(build_dir)
	rm -f $(app_name).tar.gz

package: build ## Create an installable app tarball
	rm -rf $(build_dir)
	mkdir -p $(build_dir)/$(app_name)
	cp -r appinfo lib templates img js $(build_dir)/$(app_name)/
	test -d l10n && cp -r l10n $(build_dir)/$(app_name)/ || true
	cp CHANGELOG.md LICENSE README.md $(build_dir)/$(app_name)/ 2>/dev/null || true
	cd $(build_dir) && tar -czf ../../$(app_name).tar.gz $(app_name)
	rm -rf $(build_dir)
	@echo ""
	@echo "Created: $(app_name).tar.gz"
	@tar -tzf $(app_name).tar.gz | head -12
	@echo "..."
