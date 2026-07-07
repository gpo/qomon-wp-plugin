#!/bin/bash
# Claude Code cloud environments: reference this from the environment's
# Setup script field (see README) to pre-bake this repo's dependencies into
# the cached environment image. Sessions then start warm; the SessionStart
# hook re-runs the same steps per session as a fast, idempotent reconciler.
#
# Fail-soft: a broken install must not block environment creation.
set -u
REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# All composer deps here are public, and GitHub dist downloads work at
# environment-setup time (they 403 during agent sessions). Try the real
# install first; the hook's vendor-cache fetch remains the fallback.
if [ ! -d "$REPO_DIR/vendor" ]; then
  echo "==> composer install (environment-setup time)"
  (cd "$REPO_DIR" && COMPOSER_ALLOW_SUPERUSER=1 timeout 300 composer install --no-interaction) \
    || echo "!! composer install failed - the session hook will try the vendor cache instead"
fi

CLAUDE_CODE_REMOTE=true CLAUDE_PROJECT_DIR="$REPO_DIR" \
  bash "$REPO_DIR/.claude/hooks/session-start.sh" || true
