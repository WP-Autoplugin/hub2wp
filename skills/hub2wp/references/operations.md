# hub2wp Operations Reference

## Environment checks

Run these before operating hub2wp:

```bash
wp core version
wp plugin is-active hub2wp
wp help hub2wp
```

Optional checks:

```bash
wp option get h2wp_settings --format=json
wp option get h2wp_plugins --format=json
wp option get h2wp_themes --format=json
```

## Preferred interface order

1. WP-CLI for routine local administration.
2. Abilities for structured PHP or REST execution on WordPress 6.9+.
3. Admin UI for interactive browsing or dashboard-specific tasks.

## WP-CLI commands

### Plugins

```bash
wp hub2wp plugin list
wp hub2wp plugin install owner/repo --activate
wp hub2wp plugin install owner/repo --branch=main --no-release-priority
wp hub2wp plugin install https://github.com/acme/private-plugin --token=ghp_xxx
```

### Themes

```bash
wp hub2wp theme list
wp hub2wp theme install owner/repo --activate
wp hub2wp theme install owner/repo --branch=main --no-release-priority
wp hub2wp theme install https://github.com/acme/private-theme --token=ghp_xxx
```

### Settings

```bash
wp hub2wp settings list
wp hub2wp settings list --show-secrets
wp hub2wp settings get access_token
wp hub2wp settings set access_token ghp_xxx
wp hub2wp settings set cache_duration 6
wp hub2wp settings delete access_token
```

## Registered abilities

Read-only abilities exposed in REST:

- `hub2wp/list-tracked-plugins`
- `hub2wp/list-tracked-themes`
- `hub2wp/get-repository-details`
- `hub2wp/check-repository-compatibility`

Management abilities available in PHP only unless hub2wp changes their `show_in_rest` metadata:

- `hub2wp/install-plugin-from-github`
- `hub2wp/install-theme-from-github`
- `hub2wp/clear-cache`
- `hub2wp/run-update-check`

## PHP ability usage

Check availability:

```bash
wp eval 'var_export( wp_has_ability( "hub2wp/list-tracked-plugins" ) );'
```

List tracked plugins:

```bash
wp eval '$ability = wp_get_ability( "hub2wp/list-tracked-plugins" ); var_export( $ability ? $ability->execute() : null );'
```

Get repository details:

```bash
wp eval '$ability = wp_get_ability( "hub2wp/get-repository-details" ); var_export( $ability ? $ability->execute( array( "owner" => "WP-Autoplugin", "repo" => "hub2wp", "repo_type" => "plugin" ) ) : null );'
```

Check compatibility:

```bash
wp eval '$ability = wp_get_ability( "hub2wp/check-repository-compatibility" ); var_export( $ability ? $ability->execute( array( "owner" => "WP-Autoplugin", "repo" => "hub2wp", "repo_type" => "plugin" ) ) : null );'
```

Run a management ability:

```bash
wp eval '$ability = wp_get_ability( "hub2wp/run-update-check" ); var_export( $ability ? $ability->execute() : null );'
```

Install via ability:

```bash
wp eval '$ability = wp_get_ability( "hub2wp/install-plugin-from-github" ); var_export( $ability ? $ability->execute( array( "owner" => "acme", "repo" => "my-plugin", "branch" => "main", "prioritize_releases" => true ) ) : null );'
```

## REST ability usage

Abilities marked `show_in_rest => true` are available under:

```text
/wp-json/wp-abilities/v1
```

Useful endpoints:

- list abilities: `GET /wp-json/wp-abilities/v1/abilities`
- get one ability: `GET /wp-json/wp-abilities/v1/hub2wp/get-repository-details`
- execute a read-only ability: `GET /wp-json/wp-abilities/v1/hub2wp/get-repository-details/run?input=<urlencoded-json>`

Example input JSON for read-only hub2wp abilities:

```json
{"owner":"WP-Autoplugin","repo":"hub2wp","repo_type":"plugin"}
```

Do not assume management abilities are callable over REST; hub2wp currently registers them with `show_in_rest => false`.

## UI mapping

- Browse/install plugins: `Plugins > Add GitHub Plugin`
- Browse/install themes: `Appearance > Themes > GitHub Themes`
- Manage token, cache, monitored repos, update checks: `Settings > GitHub Plugins`

## Troubleshooting

- If `wp help hub2wp` does not list commands, confirm the plugin is active and Composer autoload is present.
- If abilities are missing, confirm the site is on WordPress 6.9+ and run `wp eval 'var_export( function_exists( "wp_register_ability" ) );'`.
- If a GitHub install fails, check the repo type, repository compatibility, branch, and release-priority settings before retrying.
- If private repo access fails, verify the configured GitHub token and avoid echoing it back in logs or summaries.
