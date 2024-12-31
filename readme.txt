=== hub2wp ===
Contributors: pbalazs
Tags: github, plugins, installer
Requires at least: 5.0
Tested up to: 6.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Browse, install, and update WordPress plugins directly from GitHub repositories.

== Description ==

This plugin allows you to discover WordPress plugins hosted on GitHub by searching repositories with the "wordpress-plugin" topic. You can install and update these plugins directly from your WordPress dashboard, just like plugins from the official WordPress plugin repository.

Features:
* Search WordPress plugins in GitHub repositories.
* Install plugins with one click.
* Receive update notifications and update with one click.
* Optionally add a personal GitHub access token to increase API rate limits.
* Caching to reduce API requests.

== Installation ==

1. Upload the `hub2wp` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Navigate to "Plugins > Add GitHub Plugin" to browse plugins.
4. Go to "Settings > GitHub Plugins" to add an optional personal access token and configure caching.

== Frequently Asked Questions ==

= Do I need a GitHub token? =
No, but you have a higher request limit if you use one.

== Changelog ==

= 1.0.0 =
* Initial release.
