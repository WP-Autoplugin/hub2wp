jQuery(document).ready(function($) {
    function getResponseMessage(response, fallback) {
        return response && response.data && response.data.message ? response.data.message : fallback;
    }

    function findProjectElements(selector, owner, repo, repoType, subdirectory) {
        var normalizedOwner = String(owner || '').toLowerCase();
        var normalizedRepo = String(repo || '').toLowerCase();
        var normalizedType = repoType || h2wp_ajax_object.repo_type || 'plugin';
        var normalizedSubdirectory = subdirectory || '';

        return $(selector).filter(function() {
            return String($(this).data('owner') || '').toLowerCase() === normalizedOwner &&
                String($(this).data('repo') || '').toLowerCase() === normalizedRepo &&
                ($(this).data('type') || h2wp_ajax_object.repo_type || 'plugin') === normalizedType &&
                ($(this).data('subdirectory') || '') === normalizedSubdirectory;
        });
    }

    function getDetailsUrl(owner, repo, repoType, subdirectory) {
        var url = new URL(window.location.href);

        url.searchParams.set('h2wp_modal', 'details');
        url.searchParams.set('h2wp_modal_owner', owner);
        url.searchParams.set('h2wp_modal_repo', repo);
        url.searchParams.set('repo_type', repoType || h2wp_ajax_object.repo_type || 'plugin');
        if (subdirectory) {
            url.searchParams.set('h2wp_modal_subdirectory', subdirectory);
        } else {
            url.searchParams.delete('h2wp_modal_subdirectory');
        }

        return url.toString();
    }

    function clearDetailsUrl() {
        var url = new URL(window.location.href);

        url.searchParams.delete('h2wp_modal');
        url.searchParams.delete('h2wp_modal_owner');
        url.searchParams.delete('h2wp_modal_repo');
        url.searchParams.delete('repo_type');
        url.searchParams.delete('h2wp_modal_subdirectory');

        window.history.replaceState({}, document.title, url.toString());
    }

    function maybeUpdateDetailsUrl(owner, repo, repoType, subdirectory) {
        var nextUrl = getDetailsUrl(owner, repo, repoType, subdirectory);

        if (nextUrl !== window.location.href) {
            window.history.replaceState({}, document.title, nextUrl);
        }
    }

    function openRepositoryDetails(owner, repo, repoType, subdirectory, shouldUpdateUrl) {
        if (!owner || !repo) {
            return;
        }

        repoType = repoType || h2wp_ajax_object.repo_type || 'plugin';

        if (shouldUpdateUrl !== false) {
            maybeUpdateDetailsUrl(owner, repo, repoType, subdirectory);
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
                repo_type: repoType,
                subdirectory: subdirectory || ''
            },
            beforeSend: function() {
                $('#h2wp-plugin-modal').addClass('h2wp-modal-loading').fadeIn();
            },
            success: function(response) {
                if (response.success) {
                    loadGpbModal(response.data);
                } else {
                    clearDetailsUrl();
                    $('#h2wp-plugin-modal').hide();
                    alert(getResponseMessage(response, h2wp_ajax_object.error_message || 'An error occurred.'));
                }
            },
            error: function() {
                clearDetailsUrl();
                $('#h2wp-plugin-modal').hide();
                alert(h2wp_ajax_object.error_message || 'An error occurred.');
            },
            complete: function() {
                $('#h2wp-plugin-modal').removeClass('h2wp-modal-loading');
            }
        });
    }

    // Function to open modal with plugin details.
    function loadGpbModal(data) {
        var modal = $('#h2wp-plugin-modal');
        var repoType = data.repo_type || h2wp_ajax_object.repo_type || 'plugin';
        modal.find('.h2wp-modal-title').text(data.display_name);
        modal.find('.h2wp-modal-author').empty().append(
            $('<a>').attr({ href: data.author_url, target: '_blank', rel: 'noopener noreferrer' }).text(data.author)
        );
        modal.find('.h2wp-modal-description').text(data.description);
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
        modal.find('.h2wp-install-plugin').data('owner', data.owner).data('repo', data.repo).data('type', repoType).data('subdirectory', data.subdirectory || '');
        modal.find('.h2wp-activate-plugin').data('owner', data.owner).data('repo', data.repo).data('type', repoType).data('subdirectory', data.subdirectory || '');
        modal.find('.h2wp-activate-plugin').addClass('h2wp-hidden');

        // Also update the updated_at in the plugin card (.h2wp-meta-updated)
        findProjectElements('.h2wp-meta-updated', data.owner, data.repo, repoType, data.subdirectory).find('span').text(data.updated_at);

        // Set current tab to "readme" and show the content.
        modal.find('.h2wp-modal-tab-active').removeClass('h2wp-modal-tab-active');
        modal.find('.h2wp-modal-tab[data-tab="readme"]').addClass('h2wp-modal-tab-active');
        modal.find('.h2wp-modal-readme-content').removeClass('h2wp-hidden').siblings().addClass('h2wp-hidden');

        // Set the "Changelog" tab to not loaded.
        modal.find('.h2wp-modal-changelog-content').data('loaded', false);

        // Add topics to the modal.
        var $topics = modal.find('.h2wp-modal-topics').empty();
        data.topics.forEach(function(topic) {
            $('<span>').addClass('h2wp-modal-topic').append(
                $('<a>').attr('href', topic.url).text(topic.name)
            ).appendTo($topics);
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
            repo_type: data.repo_type || h2wp_ajax_object.repo_type || 'plugin',
            subdirectory: data.subdirectory || ''
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
                        findProjectElements('.h2wp-install-plugin', data.owner, data.repo, data.repo_type, data.subdirectory)
                            .addClass('h2wp-installed h2wp-button-disabled').text('Incompatible');
                    }

                    if (response.data.reason) {
                        modal.find('.h2wp-modal-compatibility').append(
                            $('<p>').addClass('h2wp-modal-incompatibility-reason').text(response.data.reason)
                        );
                    }

                    // Update compatibility details in the modal sidebar
                    if (response.data.headers) {
                        modal.find('.h2wp-modal-version').text(response.data.headers['version'] || response.data.headers['stable tag'] || 'Unknown');
                        modal.find('.h2wp-modal-compatibility-required-wp-version').text(response.data.headers['requires at least'] || 'Unknown');
                        modal.find('.h2wp-modal-compatibility-tested-wp-version').text(response.data.headers['tested up to'] || 'Unknown');
                        modal.find('.h2wp-modal-compatibility-required-php-version').text(response.data.headers['requires php'] || 'Unknown');
                    }
                } else {
                    alert(getResponseMessage(response, h2wp_ajax_object.error_message || 'An error occurred.'));
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
        clearDetailsUrl();
        $('#h2wp-plugin-modal').fadeOut();
    }

    // Click event for "More Details" links.
    $('.h2wp-more-details-link, .h2wp-plugin-name, .h2wp-plugin-thumbnail, .h2wp-theme-name-link').on('click', function(e) {
        var isModifiedAnchorClick = e.currentTarget.tagName === 'A' && (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button === 1);
        var owner = $(this).data('owner');
        var repo = $(this).data('repo');
        var repoType = $(this).data('type') || h2wp_ajax_object.repo_type || 'plugin';
        var subdirectory = $(this).data('subdirectory') || '';

        if (!owner || !repo || isModifiedAnchorClick) {
            return;
        }

        e.preventDefault();
        openRepositoryDetails(owner, repo, repoType, subdirectory, true);
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

    // Close the visible modal when pressing Escape.
    $(document).on('keydown', function(event) {
        if ('Escape' === event.key && $('#h2wp-plugin-modal').is(':visible')) {
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

        var subdirectory = button.data('subdirectory') || '';

        var pluginData = {
            action: 'h2wp_install_plugin',
            nonce: h2wp_ajax_object.nonce,
            owner: owner,
            repo: repo,
            repo_type: repoType,
            subdirectory: subdirectory
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
        var subdirectory = $(this).closest('#h2wp-plugin-modal').find('.h2wp-install-plugin').data('subdirectory') || '';

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
                repo_type: repoType,
                subdirectory: subdirectory
            },
            beforeSend: function() {
                tabContent.html('<div class="h2wp-loading">Loading changelog...</div>');
            },
            success: function(response) {
                if (response.success) {
                    tabContent.html(response.data.changelog_html);
                    tabContent.data('loaded', true);
                } else {
                    tabContent.empty().append($('<div>').addClass('h2wp-error').text(getResponseMessage(response, 'No changelog available.')));
                }
            },
            error: function() {
                tabContent.empty().append($('<div>').addClass('h2wp-error').text(h2wp_ajax_object.error_message || 'Failed to load changelog.'));
            }
        });
    });

    (function openRequestedDetailsFromUrl() {
        var params = new URL(window.location.href).searchParams;
        var modalType = params.get('h2wp_modal');
        var owner = params.get('h2wp_modal_owner');
        var repo = params.get('h2wp_modal_repo');
        var repoType = params.get('repo_type') || h2wp_ajax_object.repo_type || 'plugin';
        var subdirectory = params.get('h2wp_modal_subdirectory') || '';
        repoType = ('plugin' === repoType || 'theme' === repoType) ? repoType : 'plugin';

        if ('details' !== modalType || !owner || !repo) {
            return;
        }

        openRepositoryDetails(owner, repo, repoType, subdirectory, false);
    }());

    // Ellipsis text animation to show the user the action is still working
    function h2wpStartDotAnimation( $el, baseText ) {
        var dots = 1;
        $el.text( baseText + '.' );
        return setInterval( function() {
            dots = ( dots % 3 ) + 1;
            $el.text( baseText + new Array( dots + 1 ).join( '.' ) );
        }, 500 );
    }

    // Monorepo Detection — intercept "Add Repository" form
    var $addRepoForm = $('input#h2wp_private_repo_input').closest('form');
    var $repoInput   = $('#h2wp_private_repo_input');

    if ( $addRepoForm.length && $repoInput.length ) {

        // Inject picker container below the existing form description
        $repoInput.closest('td').append('<div id="h2wp-monorepo-picker" style="display:none;margin-top:12px;"></div>');

        $addRepoForm.on('submit', function(e) {
            if ( !$('#h2wp_scan_monorepo').is(':checked') ) {
                return;
            }

            var repoValue = $.trim( $repoInput.val() );
            var parts     = repoValue.split('/');

            // Only intercept clean owner/repo values
            if ( parts.length !== 2 || !parts[0] || !parts[1] ) {
                return;
            }

            e.preventDefault();

            var owner   = parts[0];
            var repo    = parts[1];
            var $btn    = $addRepoForm.find('button[type="submit"]');
            var $picker = $('#h2wp-monorepo-picker');

            $btn.prop('disabled', true);
            var detectInterval = h2wpStartDotAnimation( $btn, 'Detecting All Plugins' );
            $picker.hide().empty();

            $.ajax({
                url: h2wp_ajax_object.ajax_url,
                method: 'POST',
                data: {
                    action: 'h2wp_detect_repo_type',
                    nonce:  h2wp_ajax_object.nonce,
                    owner:  owner,
                    repo:   repo,
                    branch: $.trim( $('#h2wp_branch_input').val() )
                },
                success: function(response) {
                    if ( !response.success ) {
                        alert( getResponseMessage(response, 'Could not detect repository type.') );
                        return;
                    }

                    if ( 'single' === response.data.type ) {
                        // Single repo — disable the opt-in scan and submit normally.
                        $('#h2wp_scan_monorepo').prop('checked', false);
                        $addRepoForm.trigger('submit');
                        return;
                    }

                    // Monorepo — show the plugin picker
                    h2wpRenderMonorepoPicker( response.data.plugins, owner, repo );
                },
                error: function() {
                    alert( h2wp_ajax_object.error_message || 'An error occurred.' );
                },
                complete: function() {
                    clearInterval( detectInterval );
                    $btn.prop('disabled', false).text('Add Repository');
                }
            });
        });
    }

    /**
     * Escape a string for safe insertion into HTML.
     *
     * Folder names and subdirectory paths come from GitHub and must not be
     * concatenated directly into markup to prevent XSS.
     *
     * @param {string} str
     * @return {string}
     */
    function h2wpEscHtml( str ) {
        return String( str )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' )
            .replace( /"/g, '&quot;' )
            .replace( /'/g, '&#039;' );
    }

    function h2wpRenderMonorepoPicker( plugins, owner, repo ) {
        var $picker = $('#h2wp-monorepo-picker');
        var monitoredProjects = h2wp_ajax_object.monitored_plugin_projects || [];

        var html  = '<p><strong>Monorepo detected</strong> — ';
            html += plugins.length + ' plugin(s) found. Select which to monitor:</p>';
            html += '<label style="display:block;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #ddd;">';
            html += '<input type="checkbox" id="h2wp-select-all-plugins" checked /> <strong>Select All / Deselect All</strong>';
            html += '</label>';
            html += '<div id="h2wp-plugin-checklist" style="margin:0 0 12px;">';

        var availableCount = 0;
        plugins.forEach(function(plugin) {
            var subdirectory = plugin.subdirectory || '';
            var pathLabel = subdirectory || 'repository root';
            var projectKey = owner.toLowerCase() + '/' + repo.toLowerCase() + ( subdirectory ? '/' + subdirectory : '' );
            var monitored = monitoredProjects.indexOf( projectKey ) !== -1;
            if ( monitored ) {
                html += '<label style="display:block;margin-bottom:6px;opacity:0.55;cursor:default;">'
                    + '<input type="checkbox" class="h2wp-monorepo-plugin-cb" value="' + h2wpEscHtml( subdirectory ) + '" disabled /> '
                    + '<strong>' + h2wpEscHtml( plugin.slug ) + '</strong> '
                    + '<span style="color:#646970;font-size:12px;">(' + h2wpEscHtml( pathLabel ) + ')</span>'
                    + ' <em style="color:#2271b1;font-size:12px;">— Already Monitoring</em>'
                    + '</label>';
            } else {
                availableCount++;
                html += '<label style="display:block;margin-bottom:6px;">'
                    + '<input type="checkbox" class="h2wp-monorepo-plugin-cb" value="' + h2wpEscHtml( subdirectory ) + '" checked /> '
                    + '<strong>' + h2wpEscHtml( plugin.slug ) + '</strong> '
                    + '<span style="color:#646970;font-size:12px;">(' + h2wpEscHtml( pathLabel ) + ')</span>'
                    + '</label>';
            }
        });

        html += '</div>';
        html += '<button type="button" id="h2wp-add-selected-plugins" class="button button-primary">Add Selected Plugins (' + availableCount + ')</button> ';
        html += '<button type="button" id="h2wp-cancel-picker" class="button">Cancel</button>';
        html += '<div id="h2wp-add-repo-status" style="margin-top:10px;"></div>';

        $picker.html(html).show();

        // Select all / deselect all toggle
        $('#h2wp-select-all-plugins').on('change', function() {
            $('.h2wp-monorepo-plugin-cb:not(:disabled)').prop('checked', $(this).is(':checked'));
            var checked = $('.h2wp-monorepo-plugin-cb:not(:disabled):checked').length;
            $('#h2wp-add-selected-plugins').text( 'Add Selected Plugins (' + checked + ')' );
        });

        // Keep "select all" in sync when individual boxes are changed
        $(document).off('change.h2wpMonorepoPlugins', '.h2wp-monorepo-plugin-cb:not(:disabled)')
            .on('change.h2wpMonorepoPlugins', '.h2wp-monorepo-plugin-cb:not(:disabled)', function() {
            var total   = $('.h2wp-monorepo-plugin-cb:not(:disabled)').length;
            var checked = $('.h2wp-monorepo-plugin-cb:not(:disabled):checked').length;
            $('#h2wp-select-all-plugins').prop('checked', total === checked)
                                        .prop('indeterminate', checked > 0 && checked < total);
            $('#h2wp-add-selected-plugins').text( 'Add Selected Plugins (' + checked + ')' );
            });

        $('#h2wp-cancel-picker').on('click', function() {
            $picker.hide().empty();
        });

        $('#h2wp-add-selected-plugins').on('click', function() {
            var selected = [];
            $('.h2wp-monorepo-plugin-cb:checked').each(function() {
                selected.push( $(this).val() );
            });

            if ( !selected.length ) {
                alert('Please select at least one plugin.');
                return;
            }

            var $btn      = $(this);
            var $status   = $('#h2wp-add-repo-status');
            var branch    = $.trim( $('#h2wp_branch_input').val() );
            var prioritize = $('#h2wp_prioritize_releases').is(':checked') ? '1' : '0';

            $btn.prop('disabled', true);
            var addInterval = h2wpStartDotAnimation( $btn, 'Adding Selected Plugins' );
            $status.empty();

            h2wpAddPluginsSequentially( selected, 0, owner, repo, branch, prioritize, $btn, $status, 0, addInterval );
        });
    }

    function h2wpAddPluginsSequentially( subdirs, index, owner, repo, branch, prioritize, $btn, $status, successCount, dotInterval ) {
        if ( index >= subdirs.length ) {
            if ( dotInterval ) { clearInterval( dotInterval ); }
            h2wpRedirectAfterAdd( successCount, 'plugin' );
            return;
        }

        var subdirectory = subdirs[ index ];
        var slug         = subdirectory ? subdirectory.split('/').pop() : repo;

        $.ajax({
            url: h2wp_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action:              'h2wp_add_monitored_repo',
                nonce:               h2wp_ajax_object.nonce,
                owner:               owner,
                repo:                repo,
                branch:              branch,
                prioritize_releases: prioritize,
                subdirectory:        subdirectory
            },
            success: function(response) {
                if ( response.success ) {
                    h2wpAppendStatus( $status, '#00a32a', '✓ Added: ' + slug );
                    successCount++;
                } else {
                    var message = getResponseMessage(response, 'Unknown error');
                    h2wpAppendStatus( $status, '#d63638', '✗ ' + slug + ': ' + message );
                }
            },
            error: function() {
                h2wpAppendStatus( $status, '#d63638', '✗ Error adding ' + slug );
            },
            complete: function() {
                h2wpAddPluginsSequentially( subdirs, index + 1, owner, repo, branch, prioritize, $btn, $status, successCount, dotInterval );
            }
        });
    }

    // Monorepo Detection — intercept "Add Theme Repository" form
    var $addThemeForm = $('input#h2wp_private_theme_repo_input').closest('form');
    var $themeInput   = $('#h2wp_private_theme_repo_input');

    if ( $addThemeForm.length && $themeInput.length ) {

        $themeInput.closest('td').append('<div id="h2wp-monorepo-theme-picker" style="display:none;margin-top:12px;"></div>');

        $addThemeForm.on('submit', function(e) {
            if ( !$('#h2wp_scan_theme_monorepo').is(':checked') ) {
                return;
            }

            var repoValue = $.trim( $themeInput.val() );
            var parts     = repoValue.split('/');

            if ( parts.length !== 2 || !parts[0] || !parts[1] ) {
                return;
            }

            e.preventDefault();

            var owner   = parts[0];
            var repo    = parts[1];
            var $btn    = $addThemeForm.find('button[type="submit"]');
            var $picker = $('#h2wp-monorepo-theme-picker');

            $btn.prop('disabled', true);
            var detectInterval = h2wpStartDotAnimation( $btn, 'Detecting All Themes' );
            $picker.hide().empty();

            $.ajax({
                url: h2wp_ajax_object.ajax_url,
                method: 'POST',
                data: {
                    action:    'h2wp_detect_repo_type',
                    nonce:     h2wp_ajax_object.nonce,
                    owner:     owner,
                    repo:      repo,
                    branch:    $.trim( $('#h2wp_theme_branch_input').val() ),
                    repo_type: 'theme'
                },
                success: function(response) {
                    if ( !response.success ) {
                        alert( getResponseMessage(response, 'Could not detect repository type.') );
                        return;
                    }
                    if ( 'single' === response.data.type ) {
                        $('#h2wp_scan_theme_monorepo').prop('checked', false);
                        $addThemeForm.trigger('submit');
                        return;
                    }
                    h2wpRenderMonorepoThemePicker( response.data.plugins, owner, repo );
                },
                error: function() {
                    alert( h2wp_ajax_object.error_message || 'An error occurred.' );
                },
                complete: function() {
                    clearInterval( detectInterval );
                    $btn.prop('disabled', false).text('Add Repository');
                }
            });
        });
    }

    function h2wpRenderMonorepoThemePicker( themes, owner, repo ) {
        var $picker = $('#h2wp-monorepo-theme-picker');
        var monitoredProjects = h2wp_ajax_object.monitored_theme_projects || [];

        var html  = '<p><strong>Theme monorepo detected</strong> — ';
            html += themes.length + ' theme(s) found. Select which to monitor:</p>';
            html += '<label style="display:block;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #ddd;">';
            html += '<input type="checkbox" id="h2wp-select-all-themes" checked /> <strong>Select All / Deselect All</strong>';
            html += '</label>';
            html += '<div id="h2wp-theme-checklist" style="margin:0 0 12px;">';

        var availableCount = 0;
        themes.forEach(function(theme) {
            var subdirectory = theme.subdirectory || '';
            var pathLabel = subdirectory || 'repository root';
            var projectKey = owner.toLowerCase() + '/' + repo.toLowerCase() + ( subdirectory ? '/' + subdirectory : '' );
            var monitored = monitoredProjects.indexOf( projectKey ) !== -1;
            if ( monitored ) {
                html += '<label style="display:block;margin-bottom:6px;opacity:0.55;cursor:default;">'
                    + '<input type="checkbox" class="h2wp-monorepo-theme-cb" value="' + h2wpEscHtml( subdirectory ) + '" disabled /> '
                    + '<strong>' + h2wpEscHtml( theme.slug ) + '</strong> '
                    + '<span style="color:#646970;font-size:12px;">(' + h2wpEscHtml( pathLabel ) + ')</span>'
                    + ' <em style="color:#2271b1;font-size:12px;">— Already Monitoring</em>'
                    + '</label>';
            } else {
                availableCount++;
                html += '<label style="display:block;margin-bottom:6px;">'
                    + '<input type="checkbox" class="h2wp-monorepo-theme-cb" value="' + h2wpEscHtml( subdirectory ) + '" checked /> '
                    + '<strong>' + h2wpEscHtml( theme.slug ) + '</strong> '
                    + '<span style="color:#646970;font-size:12px;">(' + h2wpEscHtml( pathLabel ) + ')</span>'
                    + '</label>';
            }
        });

        html += '</div>';
        html += '<button type="button" id="h2wp-add-selected-themes" class="button button-primary">Add Selected Themes (' + availableCount + ')</button> ';
        html += '<button type="button" id="h2wp-cancel-theme-picker" class="button">Cancel</button>';
        html += '<div id="h2wp-add-theme-status" style="margin-top:10px;"></div>';

        $picker.html(html).show();

        $('#h2wp-select-all-themes').on('change', function() {
            $('.h2wp-monorepo-theme-cb:not(:disabled)').prop('checked', $(this).is(':checked'));
            var checked = $('.h2wp-monorepo-theme-cb:not(:disabled):checked').length;
            $('#h2wp-add-selected-themes').text( 'Add Selected Themes (' + checked + ')' );
        });

        $(document).off('change.h2wpMonorepoThemes', '.h2wp-monorepo-theme-cb:not(:disabled)')
            .on('change.h2wpMonorepoThemes', '.h2wp-monorepo-theme-cb:not(:disabled)', function() {
            var total   = $('.h2wp-monorepo-theme-cb:not(:disabled)').length;
            var checked = $('.h2wp-monorepo-theme-cb:not(:disabled):checked').length;
            $('#h2wp-select-all-themes').prop('checked', total === checked)
                                        .prop('indeterminate', checked > 0 && checked < total);
            $('#h2wp-add-selected-themes').text( 'Add Selected Themes (' + checked + ')' );
            });

        $('#h2wp-cancel-theme-picker').on('click', function() {
            $picker.hide().empty();
        });

        $('#h2wp-add-selected-themes').on('click', function() {
            var selected = [];
            $('.h2wp-monorepo-theme-cb:not(:disabled):checked').each(function() {
                selected.push( $(this).val() );
            });
            if ( !selected.length ) {
                alert('Please select at least one theme.');
                return;
            }
            var $btn      = $(this);
            var $status   = $('#h2wp-add-theme-status');
            var branch    = $.trim( $('#h2wp_theme_branch_input').val() );
            var prioritize = $('#h2wp_theme_prioritize_releases').is(':checked') ? '1' : '0';

            $btn.prop('disabled', true);
            var addInterval = h2wpStartDotAnimation( $btn, 'Adding Selected Themes' );
            $status.empty();

            h2wpAddThemesSequentially( selected, 0, owner, repo, branch, prioritize, $btn, $status, 0, addInterval );
        });
    }

    function h2wpAddThemesSequentially( subdirs, index, owner, repo, branch, prioritize, $btn, $status, successCount, dotInterval ) {
        if ( index >= subdirs.length ) {
            if ( dotInterval ) { clearInterval( dotInterval ); }
            h2wpRedirectAfterAdd( successCount, 'theme' );
            return;
        }

        var subdirectory = subdirs[ index ];
        var slug         = subdirectory ? subdirectory.split('/').pop() : repo;

        $.ajax({
            url: h2wp_ajax_object.ajax_url,
            method: 'POST',
            data: {
                action:              'h2wp_add_monitored_repo',
                nonce:               h2wp_ajax_object.nonce,
                owner:               owner,
                repo:                repo,
                branch:              branch,
                prioritize_releases: prioritize,
                subdirectory:        subdirectory,
                repo_type:           'theme'
            },
            success: function(response) {
                if ( response.success ) {
                    h2wpAppendStatus( $status, '#00a32a', '✓ Added: ' + slug );
                    successCount++;
                } else {
                    var message = getResponseMessage(response, 'Unknown error');
                    h2wpAppendStatus( $status, '#d63638', '✗ ' + slug + ': ' + message );
                }
            },
            error: function() {
                h2wpAppendStatus( $status, '#d63638', '✗ Error adding ' + slug );
            },
            complete: function() {
                h2wpAddThemesSequentially( subdirs, index + 1, owner, repo, branch, prioritize, $btn, $status, successCount, dotInterval );
            }
        });
    }

    function h2wpAppendStatus( $status, color, message ) {
        $('<p>').css('color', color).text( message ).appendTo( $status );
    }

    function h2wpRedirectAfterAdd( successCount, type ) {
        var url = new URL( window.location.href );
        url.searchParams.set( 'h2wp_added', successCount );
        url.searchParams.set( 'h2wp_added_type', type );
        window.location.href = url.toString();
    }

});
