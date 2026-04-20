APP_ID      = signotecsignosignuniversal
APP_VERSION = $(shell grep -oP '(?<=<version>)[^<]+' appinfo/info.xml)
ARCHIVE     = temp/$(APP_ID)-$(APP_VERSION).tar.gz
NC_DEV_ROOT ?= ~/nextcloud-docker-dev/workspace
NC_VERSION  ?= server

EXCLUDES = \
  .git .github .claude .vscode \
  node_modules vendor-bin vendor \
  src tests translationfiles screenshots scripts temp \
  translationtool.phar CLAUDE.md Makefile psalm.xml \
  package.json package-lock.json composer.json composer.lock \
  tsconfig.json stylelint.config.cjs vite.config.ts

TAR_EXCLUDES  = $(foreach e,$(EXCLUDES),--exclude="./$e") --exclude="./*.phar" --exclude="./.*"
RSYNC_EXCLUDES = $(foreach e,$(EXCLUDES),--exclude="$e") --exclude="*.phar" --exclude=".*"

.PHONY: release sign deploy

release:
	rm -f $(ARCHIVE)
	tar -czf $(ARCHIVE) \
	  --transform 's|^\.|$(APP_ID)|' \
	  $(TAR_EXCLUDES) \
	  .

deploy:
	sudo rsync -av . \
	  $(NC_DEV_ROOT)/$(NC_VERSION)/apps/$(APP_ID)/ \
	  $(RSYNC_EXCLUDES) \
	  --delete

sign:
	bash scripts/sign-app.sh
