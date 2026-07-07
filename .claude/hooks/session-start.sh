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

# composer install cannot work here (GitHub dist downloads 403 through the
# session proxy). CI builds the PHP dev tooling and force-pushes it to the
# orphan branch claude-vendor-cache (see
# .github/workflows/build-vendor-cache.yml), which we CAN fetch.
if [ -d vendor ]; then
  echo "==> vendor/ already present (cached container) - skipping vendor cache fetch"
elif git fetch --depth 1 origin claude-vendor-cache 2>/dev/null; then
  echo "==> extracting composer vendor cache from claude-vendor-cache branch"
  git ls-tree --name-only FETCH_HEAD \
    | grep '^vendor-cache\.tar\.gz\.part-' \
    | sort \
    | while read -r part; do git cat-file blob "FETCH_HEAD:$part"; done \
    | tar xz \
    && echo "==> vendor cache extracted" \
    || echo "!! vendor cache extraction failed - phpcs unavailable this session"
else
  echo "!! claude-vendor-cache branch not found - run the 'Build vendor cache for cloud agent sessions' workflow once on the default branch. phpcs unavailable until then."
fi

echo "==> session-start hook complete"
echo "    Run checks with: pnpm lint:js && pnpm test:js && vendor/bin/phpcs"
