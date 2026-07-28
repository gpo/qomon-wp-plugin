#!/bin/bash
set -uo pipefail

# Only run this in Claude Code on the web / remote sandboxes. The container
# state is cached after this hook completes, so installs are one-time per
# environment build, not per session.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
  exit 0
fi

cd "$CLAUDE_PROJECT_DIR"

echo "==> pnpm install"
pnpm install || echo "!! pnpm install failed - JS build/lint/tests unavailable"

# Composer's deps here are all small and public, so a direct install works
# even in-session (composer falls back to cloning from source when GitHub
# dist zipballs 403 through the session proxy). No cache needed.
if [ -d vendor ]; then
  echo "==> vendor/ already present (cached container) - skipping composer install"
elif COMPOSER_ALLOW_SUPERUSER=1 timeout 180 composer install --no-interaction; then
  echo "==> composer install succeeded"
else
  echo "!! composer install failed or timed out - phpcs unavailable this session"
  rm -rf vendor
fi

echo "==> session-start hook complete"
echo "    Run checks with: pnpm lint:js && pnpm test:js && vendor/bin/phpcs"
