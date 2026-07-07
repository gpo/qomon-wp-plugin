# Qomon WordPress Plugin

Embeds Qomon action forms into WordPress pages via a Gutenberg block or `[qomon-form]` shortcode. See [CLAUDE.md](CLAUDE.md) for architecture, development, and verification docs, and [readme.txt](readme.txt) for the WordPress plugin-directory readme.

## Claude Code cloud environments

Sessions in [Claude Code on the web](https://claude.ai/code) install this repo's dependencies at session start via `.claude/hooks/session-start.sh`. To pre-bake them into the cached environment image instead (sessions start warm, and install failures surface at environment build rather than mid-task), add this line to the environment's **Setup script** field, alongside the equivalent line from any other attached repo you want pre-built:

```bash
bash /home/user/qomon-wp-plugin/.claude/setup-env.sh || true
```

The path matches where Claude Code cloud environments clone this repo. This script also attempts a real `composer install`, which works at environment-setup time (GitHub dist downloads are blocked during agent sessions but not during setup).
