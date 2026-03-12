=== hub2wp ===
Contributors: pbalazs
Tags: github, plugins, installer
Requires at least: 5.8
Tested up to: 7.0
Stable tag: 1.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Browse, install, and update WordPress plugins directly from GitHub repositories.

== Description ==

This plugin allows you to discover WordPress themes and plugins hosted on GitHub by searching repositories with the "wordpress-plugin" topic. You can install and update these plugins directly from your WordPress dashboard, just like plugins from the official WordPress plugin repository.

Features:
* Search WordPress themes and plugins in GitHub repositories.
* Browse and install extensions from private GitHub repositories (requires personal access token with "repo" scope).
* Install themes and plugins with one click.
* Receive update notifications and update with one click.
* Optionally add a personal GitHub access token to increase API rate limits.
* Caching to reduce API requests.

== Installation ==

1. Install the latest GitHub release:
    a. With WP-CLI:
`wp plugin install "https://github.com/WP-Autoplugin/hub2wp/archive/refs/tags/$(curl -fsSL https://api.github.com/repos/WP-Autoplugin/hub2wp/releases/latest | php -r '$release = json_decode(stream_get_contents(STDIN), true); echo $release["tag_name"];').zip" --activate`
    b. Or download the latest release from GitHub and upload it to your WordPress plugins directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to "Plugins > Add GitHub Plugin" to browse plugins.
4. Go to "Settings > GitHub Plugins" to add an optional personal access token and configure caching.

== WP-CLI ==

Install and track a GitHub plugin so hub2wp can monitor updates for it:
`wp hub2wp plugin install owner/repo --activate`
List tracked plugins:
`wp hub2wp plugin list`

Install and track a GitHub theme too:
`wp hub2wp theme install owner/repo --activate`
List tracked themes:
`wp hub2wp theme list`

Optional flags:
* `--branch=<branch>` to track a specific branch.
* `--no-release-priority` to force branch-based tracking even if releases exist.
* `--token=<token>` to install from a private repository or override the stored hub2wp token for that command.

Manage hub2wp settings with WP-CLI too:
* `wp hub2wp settings set access_token ghp_xxx`
* `wp hub2wp settings set cache_duration 6`
* `wp hub2wp settings get access_token`
* `wp hub2wp settings list`

== Frequently Asked Questions ==

= Do I need a GitHub token? =
No, but you have a higher request limit if you use one.

== Changelog ==

= 1.5.0 =
* New: Added WP-CLI support - commands for installing, listing, and managing tracked plugins and themes, as well as hub2wp settings.
* New: Added integration with the Abilities API for WordPress 6.9+ to allow AI agents and automation tools to interact with hub2wp features and manage GitHub-hosted plugins and themes.
* New: Added a structured "skill" for AI coding assistants and autonomous agents to understand how to operate the plugin correctly.

= 1.4.0 =
* New: Use latest release files to check for updates if available, otherwise fall back to the selected branch.

= 1.3.0 =
* New: Added support for monitoring a specified branch for updates in addition to the default branch.

= 1.2.0 =
* New: Added support for themes in addition to plugins.
* New: Added filter link for GitHub plugins on the Plugins page.
* Fix: Use repo name as the plugin or theme slug on installation.

= 1.1.0 =
* New: Added support for private GitHub repositories.
* New: Private repositories tab in plugin browser.
* New: Settings page UI for managing repositories / monitored plugins.

= 1.0.1 =
* Fix: Update plugin data saved in the activation hook.
* Fix: Use the last commit date from the default branch as the plugin's last updated date.

= 1.0.0 =
* Initial release.
