jQuery(document).ready(function($) {
    // Function to open modal with plugin details.
    function loadGpbModal(data) {
        var modal = $('#gpb-plugin-modal');
        modal.find('.gpb-modal-title').text(data.display_name);
        modal.find('.gpb-modal-author').html('<a href="' + data.author_url + '" target="_blank">' + data.author + '</a>');
        modal.find('.gpb-modal-description').html(data.description);
        modal.find('.gpb-modal-stars').text(data.stargazers);
        modal.find('.gpb-modal-forks').text(data.forks);
        modal.find('.gpb-modal-watchers').text(data.watchers);
        modal.find('.gpb-modal-issues').text(data.open_issues);
        modal.find('.gpb-modal-updated').text(data.updated_at);
        modal.find('.gpb-modal-github-link').attr('href', data.html_url);
        modal.find('.gpb-modal-header').css('background-image', 'url(' + data.og_image + ')');
        if (data.is_installed) {
            modal.find('.gpb-install-plugin').addClass('gpb-installed gpb-button-disabled').text('Installed');
        } else {
            modal.find('.gpb-install-plugin').removeClass('gpb-installed gpb-button-disabled').text('Install Now');
        }
        modal.find('.gpb-install-plugin').data('owner', data.owner).data('repo', data.repo);
        modal.find('.gpb-activate-plugin').addClass('gpb-hidden');

        // Set current tab to "readme" and show the content.
        modal.find('.gpb-modal-tab-active').removeClass('gpb-modal-tab-active');
        modal.find('.gpb-modal-tab[data-tab="readme"]').addClass('gpb-modal-tab-active');
        modal.find('.gpb-modal-readme-content').removeClass('gpb-hidden').siblings().addClass('gpb-hidden');

        // Set the "Changelog" tab to not loaded.
        modal.find('.gpb-modal-changelog-content').data('loaded', false);

        // Add topics to the modal.
        modal.find('.gpb-modal-topics').html(function() {
            var topics = '';
            data.topics.forEach(function(topic) {
                var topicLink = '<a href="' + topic.url + '">' + topic.name + '</a>';
                topics += '<span class="gpb-modal-topic">' + topicLink + '</span>';
            });
            return topics;
        });

        // Show or hide the p right before .gpb-modal-topics based on whether there are topics.
        modal.find('.gpb-modal-topics').prev('p').toggle(data.topics.length > 0);

        if (data.homepage) {
            modal.find('.gpb-modal-homepage-link').attr('href', data.homepage).show();
        } else {
            modal.find('.gpb-modal-homepage-link').hide();
        }
        modal.find('.gpb-modal-readme-content').html(data.readme);

        checkCompatibility(data);
    }

    // Function to check compatibility with current site via AJAX.
    function checkCompatibility(data) {
        var modal = $('#gpb-plugin-modal');
        var pluginData = {
            action: 'gpb_check_compatibility',
            nonce: gpb_ajax_object.nonce,
            owner: data.owner,
            repo: data.repo
        };

        modal.find('.gpb-modal-compatibility').html('Checking compatibility...').parent().addClass('gpb-loading');
        modal.find('.gpb-modal-version').text('Unknown');
        modal.find('.gpb-modal-compatibility-required-wp-version').text('Unknown');
        modal.find('.gpb-modal-compatibility-tested-wp-version').text('Unknown');
        modal.find('.gpb-modal-compatibility-required-php-version').text('Unknown');

        // Make AJAX request to check compatibility.
        $.ajax({
            url: gpb_ajax_object.ajax_url,
            method: 'POST',
            data: pluginData,
            beforeSend: function() {
                // Loading indicator comes here.
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.is_compatible) {
                        modal.find('.gpb-modal-compatibility').html('<span class="gpb-modal-compatible">Compatible with your site</span>');
                        modal.find('.gpb-install-plugin').removeClass('gpb-hidden');
                        modal.find('.gpb-activate-plugin').addClass('gpb-hidden');
                    } else {
                        modal.find('.gpb-modal-compatibility').html('<span class="gpb-modal-incompatible">Not compatible with your site</span>');
                        modal.find('.gpb-install-plugin').addClass('gpb-hidden');
                        modal.find('.gpb-activate-plugin').addClass('gpb-hidden');
                        // Also disable the "Install Now" button inside the plugin card.
                        $('.gpb-install-plugin[data-owner="' + data.owner + '"][data-repo="' + data.repo + '"]').addClass('gpb-installed gpb-button-disabled').text('Incompatible');
                    }

                    if (response.data.reason) {
                        modal.find('.gpb-modal-compatibility').append('<p class="gpb-modal-incompatibility-reason">' + response.data.reason + '</p>');
                    }

                    // Update compatibility details in the modal sidebar
                    if (response.data.headers) {
                        modal.find('.gpb-modal-version').text(response.data.headers['stable tag'] || 'Unknown');
                        modal.find('.gpb-modal-compatibility-required-wp-version').text(response.data.headers['requires at least'] || 'Unknown');
                        modal.find('.gpb-modal-compatibility-tested-wp-version').text(response.data.headers['tested up to'] || 'Unknown');
                        modal.find('.gpb-modal-compatibility-required-php-version').text(response.data.headers['requires php'] || 'Unknown');
                    }
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert(gpb_ajax_object.error_message || 'An error occurred.');
            },
            complete: function() {
                modal.find('.gpb-modal-compatibility').parent().removeClass('gpb-loading');
            }
        });
    }

    // Function to close modal.
    function closeGpbModal() {
        $('#gpb-plugin-modal').fadeOut();
    }

    // Click event for "More Details" links.
    $('.gpb-more-details-link, .gpb-plugin-name, .gpb-plugin-thumbnail').on('click', function(e) {
        e.preventDefault();
        var owner = $(this).data('owner');
        var repo = $(this).data('repo');

        if (!owner || !repo) {
            return;
        }

        // Make AJAX request to get plugin details.
        $.ajax({
            url: gpb_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'gpb_get_plugin_details',
                nonce: gpb_ajax_object.nonce,
                owner: owner,
                repo: repo
            },
            beforeSend: function() {
                $('#gpb-plugin-modal').addClass('gpb-modal-loading').fadeIn();
            },
            success: function(response) {
                if (response.success) {
                    loadGpbModal(response.data);
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                $('#gpb-plugin-modal').hide();
                alert(gpb_ajax_object.error_message || 'An error occurred.');
            },
            complete: function() {
                $('#gpb-plugin-modal').removeClass('gpb-modal-loading');
            }
        });
    });

    // Click event to close the modal.
    $('.gpb-modal-close').on('click', function(e) {
        e.preventDefault();
        closeGpbModal();
    });

    // Click outside the modal content to close.
    $(window).on('click', function(event) {
        var modal = $('#gpb-plugin-modal');
        if (event.target === modal[0]) {
            closeGpbModal();
        }
    });

    // Click on ".gpb-install-plugin": AJAX request to gpb_install_plugin.
    $('.gpb-install-plugin').on('click', function(e) {
        e.preventDefault();
        var button = $(this);
        var owner = button.data('owner');
        var repo = button.data('repo');

        if (!owner || !repo) {
            return;
        }

        var pluginData = {
            action: 'gpb_install_plugin',
            nonce: gpb_ajax_object.nonce,
            owner: owner,
            repo: repo
        };

        // Make AJAX request to install plugin.
        $.ajax({
            url: gpb_ajax_object.ajax_url,
            method: 'POST',
            data: pluginData,
            beforeSend: function() {
                button.addClass('gpb-loading').text('Installing...');
            },
            success: function(response) {
                if (response.success) {
                    button.addClass('gpb-hidden').siblings('.gpb-activate-plugin').removeClass('gpb-hidden').attr( 'href', response.data.activate_url );
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert(gpb_ajax_object.error_message || 'An error occurred.');
            },
            complete: function() {
                button.removeClass('gpb-loading').text('Install Now');
            }
        });
    });

    /* Tabs functionality for the modal */
    $('.gpb-modal-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        $('.gpb-modal-tab-active').removeClass('gpb-modal-tab-active');
        $(this).addClass('gpb-modal-tab-active');
        $('#gpb-plugin-modal .gpb-modal-' + tab + '-content').removeClass('gpb-hidden').siblings().addClass('gpb-hidden');
    });

    // Get changelog content via AJAX.
    $('.gpb-modal-changelog-tab').on('click', function(e) {
        e.preventDefault();
        var tabContent = $('.gpb-modal-changelog-content');
        
        // Skip if content is already loaded
        if (tabContent.data('loaded')) {
            return;
        }

        var owner = $(this).closest('#gpb-plugin-modal').find('.gpb-install-plugin').data('owner');
        var repo = $(this).closest('#gpb-plugin-modal').find('.gpb-install-plugin').data('repo');

        if (!owner || !repo) {
            return;
        }

        // Make AJAX request to get changelog
        $.ajax({
            url: gpb_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action: 'gpb_get_changelog',
                nonce: gpb_ajax_object.nonce,
                owner: owner,
                repo: repo
            },
            beforeSend: function() {
                tabContent.html('<div class="gpb-loading">Loading changelog...</div>');
            },
            success: function(response) {
                if (response.success) {
                    tabContent.html(response.data.changelog_html);
                    tabContent.data('loaded', true);
                } else {
                    tabContent.html('<div class="gpb-error">' + response.data.message + '</div>');
                }
            },
            error: function() {
                tabContent.html('<div class="gpb-error">' + (gpb_ajax_object.error_message || 'Failed to load changelog.') + '</div>');
            }
        });
    });
});
