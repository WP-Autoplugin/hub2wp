jQuery(document).ready(function($) {
    // Function to open modal with plugin details.
    function loadGpbModal(data) {
        var modal = $('#h2wp-plugin-modal');
        var repoType = data.repo_type || h2wp_ajax_object.repo_type || 'plugin';
        modal.find('.h2wp-modal-title').text(data.display_name);
        modal.find('.h2wp-modal-author').html('<a href="' + data.author_url + '" target="_blank">' + data.author + '</a>');
        modal.find('.h2wp-modal-description').html(data.description);
        modal.find('.h2wp-modal-stars').text(data.stargazers);
        modal.find('.h2wp-modal-forks').text(data.forks);
        modal.find('.h2wp-modal-watchers').text(data.watchers);
        modal.find('.h2wp-modal-issues').text(data.open_issues);
        modal.find('.h2wp-modal-updated').text(data.updated_at);
        modal.find('.h2wp-modal-github-link').attr('href', data.html_url);
        modal.find('.h2wp-modal-header').css('background-image', 'url(' + data.og_image + ')');
        if (data.is_installed) {
            modal.find('.h2wp-install-plugin').addClass('h2wp-installed h2wp-button-disabled').text('Installed');
        } else {
            modal.find('.h2wp-install-plugin').removeClass('h2wp-installed h2wp-button-disabled').text('Install Now');
        }
        modal.find('.h2wp-install-plugin').data('owner', data.owner).data('repo', data.repo).data('type', repoType);
        modal.find('.h2wp-activate-plugin').data('owner', data.owner).data('repo', data.repo).data('type', repoType);
        modal.find('.h2wp-activate-plugin').addClass('h2wp-hidden');

        // Also update the updated_at in the plugin card (.h2wp-meta-updated)
        $('.h2wp-meta-updated[data-owner="' + data.owner + '"][data-repo="' + data.repo + '"][data-type="' + repoType + '"] span').text(data.updated_at);

        // Set current tab to "readme" and show the content.
        modal.find('.h2wp-modal-tab-active').removeClass('h2wp-modal-tab-active');
        modal.find('.h2wp-modal-tab[data-tab="readme"]').addClass('h2wp-modal-tab-active');
        modal.find('.h2wp-modal-readme-content').removeClass('h2wp-hidden').siblings().addClass('h2wp-hidden');

        // Set the "Changelog" tab to not loaded.
        modal.find('.h2wp-modal-changelog-content').data('loaded', false);

        // Add topics to the modal.
        modal.find('.h2wp-modal-topics').html(function() {
            var topics = '';
            data.topics.forEach(function(topic) {
                var topicLink = '<a href="' + topic.url + '">' + topic.name + '</a>';
                topics += '<span class="h2wp-modal-topic">' + topicLink + '</span>';
            });
            return topics;
        });

        // Show or hide the p right before .h2wp-modal-topics based on whether there are topics.
        modal.find('.h2wp-modal-topics').prev('p').toggle(data.topics.length > 0);

        if (data.homepage) {
            modal.find('.h2wp-modal-homepage-link').attr('href', data.homepage).show();
        } else {
            modal.find('.h2wp-modal-homepage-link').hide();
        }
        modal.find('.h2wp-modal-readme-content').html(data.readme);

        checkCompatibility(data);
    }

    // Function to check compatibility with current site via AJAX.
    function checkCompatibility(data) {
        var modal = $('#h2wp-plugin-modal');
        var pluginData = {
            action: 'h2wp_check_compatibility',
            nonce: h2wp_ajax_object.nonce,
            owner: data.owner,
            repo: data.repo,
            repo_type: data.repo_type || h2wp_ajax_object.repo_type || 'plugin'
        };

        modal.find('.h2wp-modal-compatibility').html('Checking compatibility...').parent().addClass('h2wp-loading');
        modal.find('.h2wp-modal-version').text('Unknown');
        modal.find('.h2wp-modal-compatibility-required-wp-version').text('Unknown');
        modal.find('.h2wp-modal-compatibility-tested-wp-version').text('Unknown');
        modal.find('.h2wp-modal-compatibility-required-php-version').text('Unknown');

        // Make AJAX request to check compatibility.
        $.ajax({
            url: h2wp_ajax_object.ajax_url,
            method: 'POST',
            data: pluginData,
            beforeSend: function() {
                // Loading indicator comes here.
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.is_compatible) {
                        modal.find('.h2wp-modal-compatibility').html('<span class="h2wp-modal-compatible">Compatible with your site</span>');
                        modal.find('.h2wp-install-plugin').removeClass('h2wp-hidden');
                        modal.find('.h2wp-activate-plugin').addClass('h2wp-hidden');
                    } else {
                        modal.find('.h2wp-modal-compatibility').html('<span class="h2wp-modal-incompatible">Not compatible with your site</span>');
                        modal.find('.h2wp-install-plugin').addClass('h2wp-hidden');
                        modal.find('.h2wp-activate-plugin').addClass('h2wp-hidden');
                        // Also disable the "Install Now" button inside the plugin card.
                        $('.h2wp-install-plugin[data-owner="' + data.owner + '"][data-repo="' + data.repo + '"][data-type="' + (data.repo_type || h2wp_ajax_object.repo_type || 'plugin') + '"]').addClass('h2wp-installed h2wp-button-disabled').text('Incompatible');
                    }

                    if (response.data.reason) {
                        modal.find('.h2wp-modal-compatibility').append('<p class="h2wp-modal-incompatibility-reason">' + response.data.reason + '</p>');
                    }

                    // Update compatibility details in the modal sidebar
                    if (response.data.headers) {
                        modal.find('.h2wp-modal-version').text(response.data.headers['version'] || response.data.headers['stable tag'] || 'Unknown');
                        modal.find('.h2wp-modal-compatibility-required-wp-version').text(response.data.headers['requires at least'] || 'Unknown');
                        modal.find('.h2wp-modal-compatibility-tested-wp-version').text(response.data.headers['tested up to'] || 'Unknown');
                        modal.find('.h2wp-modal-compatibility-required-php-version').text(response.data.headers['requires php'] || 'Unknown');
                    }
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert(h2wp_ajax_object.error_message || 'An error occurred.');
            },
            complete: function() {
                modal.find('.h2wp-modal-compatibility').parent().removeClass('h2wp-loading');
            }
        });
    }

    // Function to close modal.
    function closeGpbModal() {
        $('#h2wp-plugin-modal').fadeOut();
    }

    // Click event for "More Details" links.
    $('.h2wp-more-details-link, .h2wp-plugin-name, .h2wp-plugin-thumbnail').on('click', function(e) {
        e.preventDefault();
        var owner = $(this).data('owner');
        var repo = $(this).data('repo');
        var repoType = $(this).data('type') || h2wp_ajax_object.repo_type || 'plugin';

        if (!owner || !repo) {
            return;
        }

        // Make AJAX request to get plugin details.
        $.ajax({
            url: h2wp_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'h2wp_get_plugin_details',
                nonce: h2wp_ajax_object.nonce,
                owner: owner,
                repo: repo,
                repo_type: repoType
            },
            beforeSend: function() {
                $('#h2wp-plugin-modal').addClass('h2wp-modal-loading').fadeIn();
            },
            success: function(response) {
                if (response.success) {
                    loadGpbModal(response.data);
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                $('#h2wp-plugin-modal').hide();
                alert(h2wp_ajax_object.error_message || 'An error occurred.');
            },
            complete: function() {
                $('#h2wp-plugin-modal').removeClass('h2wp-modal-loading');
            }
        });
    });

    // Click event to close the modal.
    $('.h2wp-modal-close').on('click', function(e) {
        e.preventDefault();
        closeGpbModal();
    });

    // Click outside the modal content to close.
    $(window).on('click', function(event) {
        var modal = $('#h2wp-plugin-modal');
        if (event.target === modal[0]) {
            closeGpbModal();
        }
    });

    // Click on ".h2wp-install-plugin": AJAX request to h2wp_install_plugin.
    $('.h2wp-install-plugin').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var owner = button.data('owner');
        var repo = button.data('repo');
        var repoType = button.data('type') || h2wp_ajax_object.repo_type || 'plugin';

        if (!owner || !repo) {
            return;
        }

        var pluginData = {
            action: 'h2wp_install_plugin',
            nonce: h2wp_ajax_object.nonce,
            owner: owner,
            repo: repo,
            repo_type: repoType
        };

        // Make AJAX request to install plugin.
        $.ajax({
            url: h2wp_ajax_object.ajax_url,
            method: 'POST',
            dataType: 'json',
            data: pluginData,
            beforeSend: function() {
                button.addClass('h2wp-loading').text('Installing...');
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    button.addClass('h2wp-hidden').siblings('.h2wp-activate-plugin').removeClass('h2wp-hidden').attr( 'href', response.data.activate_url );
                    button.closest('.theme-actions').find('.h2wp-button-disabled,.disabled').addClass('h2wp-hidden');
                } else {
                    alert((response && response.data && response.data.message) ? response.data.message : (h2wp_ajax_object.error_message || 'An error occurred.'));
                }
            },
            error: function() {
                alert(h2wp_ajax_object.error_message || 'An error occurred.');
            },
            complete: function() {
                button.removeClass('h2wp-loading').text('Install Now');
            }
        });
    });

    /* Tabs functionality for the modal */
    $('.h2wp-modal-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.h2wp-modal-tab-active').removeClass('h2wp-modal-tab-active');
        $(this).addClass('h2wp-modal-tab-active');
        $('#h2wp-plugin-modal .h2wp-modal-' + tab + '-content').removeClass('h2wp-hidden').siblings().addClass('h2wp-hidden');
    });

    // Get changelog content via AJAX.
    $('.h2wp-modal-changelog-tab').on('click', function(e) {
        e.preventDefault();
        var tabContent = $('.h2wp-modal-changelog-content');
        
        // Skip if content is already loaded
        if (tabContent.data('loaded')) {
            return;
        }

        var owner = $(this).closest('#h2wp-plugin-modal').find('.h2wp-install-plugin').data('owner');
        var repo = $(this).closest('#h2wp-plugin-modal').find('.h2wp-install-plugin').data('repo');
        var repoType = $(this).closest('#h2wp-plugin-modal').find('.h2wp-install-plugin').data('type') || h2wp_ajax_object.repo_type || 'plugin';

        if (!owner || !repo) {
            return;
        }

        // Make AJAX request to get changelog
        $.ajax({
            url: h2wp_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'h2wp_get_changelog',
                nonce: h2wp_ajax_object.nonce,
                owner: owner,
                repo: repo,
                repo_type: repoType
            },
            beforeSend: function() {
                tabContent.html('<div class="h2wp-loading">Loading changelog...</div>');
            },
            success: function(response) {
                if (response.success) {
                    tabContent.html(response.data.changelog_html);
                    tabContent.data('loaded', true);
                } else {
                    tabContent.html('<div class="h2wp-error">' + response.data.message + '</div>');
                }
            },
            error: function() {
                tabContent.html('<div class="h2wp-error">' + (h2wp_ajax_object.error_message || 'Failed to load changelog.') + '</div>');
            }
        });
    });
});
