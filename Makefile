APP_ID      = signotecsignosignuniversal
APP_VERSION = $(shell grep -oP '(?<=<version>)[^<]+' appinfo/info.xml)
ARCHIVE     = temp/$(APP_ID)-$(APP_VERSION).tar.gz

.PHONY: release sign
release:
	rm -f $(ARCHIVE)
	tar -czf $(ARCHIVE) \
	  --transform 's|^\.|$(APP_ID)|' \
	  --exclude="./.git" \
	  --exclude="./.github" \
	  --exclude="./.claude" \
	  --exclude="./.vscode" \
	  --exclude="./node_modules" \
	  --exclude="./vendor-bin" \
	  --exclude="./vendor" \
	  --exclude="./src" \
	  --exclude="./tests" \
	  --exclude="./translationfiles" \
	  --exclude="./translationtool.phar" \
	  --exclude="./screenshots" \
	  --exclude="./scripts" \
	  --exclude="./temp" \
	  --exclude="./package.json" \
	  --exclude="./package-lock.json" \
	  --exclude="./composer.json" \
	  --exclude="./composer.lock" \
	  --exclude="./tsconfig.json" \
	  --exclude="./stylelint.config.cjs" \
	  --exclude="./vite.config.ts" \
	  --exclude="./psalm.xml" \
	  --exclude="./Makefile" \
	  --exclude="./*.phar" \
	  --exclude="./.*" \
	  --exclude="./CLAUDE.md" \
	  .

sign:
	bash scripts/sign-app.sh
