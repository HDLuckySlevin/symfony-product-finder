# Makefile für Symfony-Doku-Export nach docs/_generated/
SHELL := /bin/bash

DOCS_DIR := docs
GEN_DIR  := $(DOCS_DIR)/_generated
CONSOLE  := php bin/console
PHPUNIT  := ./vendor/bin/phpunit
PHPSTAN  := ./vendor/bin/phpstan
RECTOR   := ./vendor/bin/rector

# Prüffunktion: existiert ein Kommando?
define has_cmd
	@if ! command -v $(1) >/dev/null 2>&1; then \
		echo "Hinweis: '$(1)' ist nicht installiert oder nicht im PATH."; \
		exit 1; \
	fi
endef

.PHONY: help docs-init docs-generate docs-clean

help:
	@echo "Targets:"
	@echo "  make docs-init       - legt $(GEN_DIR) an"
	@echo "  make docs-generate   - erzeugt alle Rohdaten in $(GEN_DIR)"
	@echo "  make docs-clean      - löscht erzeugte Rohdaten"

docs-init:
	@mkdir -p $(GEN_DIR)

docs-generate: docs-init
	@echo "==> Composer-Metadaten & Abhängigkeiten"
	@composer show -D > $(GEN_DIR)/composer-deps.txt || true
	@{ command -v jq >/dev/null 2>&1 && jq . composer.json > $(GEN_DIR)/composer.json.pretty; } || cp composer.json $(GEN_DIR)/composer.json.pretty

	@echo "==> Symfony-Infos"
	@$(CONSOLE) about > $(GEN_DIR)/symfony-about.txt
	@$(CONSOLE) debug:router --format=json > $(GEN_DIR)/router.json
	@$(CONSOLE) debug:container --show-arguments > $(GEN_DIR)/container.txt
	@$(CONSOLE) debug:autowiring > $(GEN_DIR)/autowiring.txt

	@echo "==> Doctrine / DB"
	@$(CONSOLE) doctrine:mapping:info > $(GEN_DIR)/doctrine-mapping.txt
	@$(CONSOLE) doctrine:schema:validate --skip-sync > $(GEN_DIR)/doctrine-validate.txt || true

	@echo "==> Messenger / Scheduler (optional)"
	@$(CONSOLE) messenger:stats > $(GEN_DIR)/messenger-stats.txt 2>/dev/null || true
	@$(CONSOLE) debug:scheduler > $(GEN_DIR)/scheduler.txt 2>/dev/null || true

	@echo "==> Tests & Qualität (optional)"
	@$(PHPUNIT) --list-tests > $(GEN_DIR)/phpunit-tests.txt 2>/dev/null || true
	@$(PHPSTAN) analyse --error-format=json > $(GEN_DIR)/phpstan.json 2>/dev/null || true
	@$(RECTOR) process --dry-run > $(GEN_DIR)/rector-dry-run.txt 2>/dev/null || true

	@echo "Fertig. Dateien liegen in $(GEN_DIR)/"

docs-clean:
	@rm -rf $(GEN_DIR)
	@echo "Bereinigt: $(GEN_DIR) entfernt."