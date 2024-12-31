# hub2wp

hub2wp is a WordPress plugin that lets you discover, install, and update plugins hosted on GitHub directly from your WordPress dashboard. By leveraging GitHub's plugin ecosystem, it provides a straightforward way to extend your WordPress site beyond the plugins available on WordPress.org.

---

## Features

- **Search GitHub Plugins**: Browse repositories tagged with the `wordpress-plugin` topic.
- **Install with One Click**: Easily add GitHub-hosted plugins to your site.
- **Update Management**: Receive notifications and perform updates for GitHub plugins directly from your admin panel.
- **Optional GitHub Token Support**: Enhance API rate limits by adding a personal access token.
- **Caching**: Built-in caching minimizes API requests for faster performance.

---

## How It Works

1. **Plugin Eligibility**:  
   A repository must meet these requirements to appear in hub2wp’s search results:
   - The repository must include the `wordpress-plugin` topic on GitHub.
   - A `Stable tag:` header must be present in either `readme.txt` or `readme.md` in the root folder. This is used to determine the plugin's version.

2. **Update Mechanism**:  
   Currently, hub2wp checks the `Stable tag:` version in the default branch of the repository to manage updates. In the future, support for GitHub releases will be added, prioritizing them for update monitoring if the repository uses the releases feature.

3. **Installation**:  
   - Download the latest release from the [Releases](https://github.com/YourGithubUsername/hub2wp/releases) page.
   - Upload the ZIP file via the 'Plugins' screen in WordPress or extract it to the `/wp-content/plugins/` directory.
   - Activate hub2wp from the 'Plugins' menu.
   - Start exploring GitHub plugins under “Plugins > Add GitHub Plugin.”

4. **Configuration (Optional)**:  
   - Add a personal GitHub token in “Settings > GitHub Plugins” to increase API limits.
   - Adjust caching settings for optimized performance.

---

## Roadmap

hub2wp will continue to evolve with the following planned features:

- **Release Tracking**: Monitor GitHub releases for updates instead of just the default branch.
- **Manual Update Monitoring**: Allow manual setup of update monitoring for plugins installed outside hub2wp.
- **Private Repository Support**: Extend functionality to private GitHub repositories.
- **Custom Branch Monitoring**: Enable update tracking for specific branches other than the main branch.

---

## FAQs

**Do I need a GitHub token?**  
No, but adding one increases the API request limit, which may be useful for high usage scenarios.

**Will this plugin interfere with WordPress.org plugins?**  
No, hub2wp operates independently of WordPress.org and only manages plugins sourced from GitHub.

**What happens if a plugin is updated on GitHub?**  
If the plugin has been installed on your site via hub2wp, then you will be notified, and you can update the plugin with a single click.

**Can I use hub2wp for private repositories?**  
Not yet, but support for private repositories is planned for a future release.

---

## Technical Details

- **Requires WordPress**: 5.0 or higher
- **Tested Up To**: 6.3
- **License**: GPLv2 or later

---

## Contribution

hub2wp is open source and welcomes contributions. If you encounter issues or have suggestions, please create an issue or pull request in the [GitHub repository](https://github.com/YourGithubUsername/hub2wp).

---

## Changelog

### 1.0.0
- Initial release
