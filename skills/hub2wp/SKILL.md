---
name: hub2wp
description: Use when the agent needs to install, inspect, monitor, or maintain GitHub-hosted WordPress plugins and themes. Trigger for tasks involving hub2wp WP-CLI commands, GitHub-tracked plugin/theme listings, hub2wp settings, cache clearing, update checks, or hub2wp abilities.
---

# hub2wp

Operate the hub2wp plugin through its existing interfaces instead of reimplementing its behavior. Prefer WP-CLI for routine operational tasks, use hub2wp abilities when WordPress 6.9+ is available and the task maps cleanly to a registered ability, and use the admin UI only when the task is explicitly UI-focused.

## Quick Start

1. Verify the environment.
   - Confirm WordPress is reachable with `wp core version`.
   - Confirm hub2wp is active with `wp plugin is-active hub2wp`.
   - Confirm hub2wp commands are available with `wp help hub2wp`.

2. Pick the interface.
   - Use WP-CLI for install, list, and settings tasks.
   - Use abilities for structured PHP/REST execution on WordPress 6.9+.
   - Use the admin UI only for browser-based workflows or when the user explicitly asks about the dashboard flow.

3. Preserve hub2wp semantics.
   - Let hub2wp install and track repositories instead of using raw ZIP installs when update monitoring is required.
   - Respect the repository type (`plugin` or `theme`), `branch`, and `prioritize_releases` inputs.
   - Do not expose or print secrets unless the user explicitly asks for them.

## Workflow

### Routine operations

Prefer these commands first:

- Install and track a plugin: `wp hub2wp plugin install owner/repo [--branch=<branch>] [--no-release-priority] [--activate] [--token=<token>]`
- Install and track a theme: `wp hub2wp theme install owner/repo [--branch=<branch>] [--no-release-priority] [--activate] [--token=<token>]`
- List tracked plugins: `wp hub2wp plugin list`
- List tracked themes: `wp hub2wp theme list`
- Manage settings: `wp hub2wp settings list|get|set|delete ...`

Load [references/operations.md](references/operations.md) when you need the exact command matrix or examples.

### Abilities

Use abilities when:

- the site runs WordPress 6.9+,
- the task benefits from structured input/output,
- or another system wants to call hub2wp functionality through PHP or REST.

Before using an ability, confirm it exists:

- Check a single ability in PHP with `wp eval 'var_export( wp_has_ability( "hub2wp/list-tracked-plugins" ) );'`
- Inspect one ability with `wp eval '$ability = wp_get_ability( "hub2wp/get-repository-details" ); var_export( $ability ? $ability->get_input_schema() : null );'`

Load [references/operations.md](references/operations.md) for the full ability list, PHP execution examples, and REST exposure notes.

### Admin UI fallback

Use the dashboard only when needed:

- `Plugins > Add GitHub Plugin` for interactive browsing and installation.
- `Appearance > Themes > GitHub Themes` for theme browsing.
- `Settings > GitHub Plugins` for monitored repositories, token management, cache clearing, and manual update checks.

## Guardrails

- Verify `hub2wp` is active before giving operational guidance.
- Prefer hub2wp install commands over `wp plugin install` or `wp theme install` when the goal includes update tracking.
- Treat GitHub tokens as secrets. Mask them in outputs unless the user explicitly asks to reveal them.
- Do not assume abilities exist on sites below WordPress 6.9 or on installs where hub2wp is inactive.
- When troubleshooting, inspect tracked plugin/theme state before changing settings or reinstalling.

## References

- Read [references/operations.md](references/operations.md) for exact commands, ability names, and interface selection details.
