jQuery(document).ready(function($) {
    // Function to open modal with plugin details.
    function openGpbModal(data) {
        var modal = $('#gpb-plugin-modal');
        modal.find('.gpb-modal-image').attr('src', data.og_image);
        modal.find('.gpb-modal-title').text(data.name);
        modal.find('.gpb-modal-author').html('By <a href="' + data.author_url + '" target="_blank">' + data.author + '</a>');
        modal.find('.gpb-modal-description').text(data.description);
        modal.find('.gpb-modal-stars').text(data.stargazers);
        modal.find('.gpb-modal-forks').text(data.forks);
        modal.find('.gpb-modal-watchers').text(data.watchers);
        modal.find('.gpb-modal-issues').text(data.open_issues);
        modal.find('.gpb-modal-version').text(data.version);
        modal.find('.gpb-modal-updated').text(data.updated_at);
        modal.find('.gpb-modal-github-link').attr('href', data.html_url);
        if (data.homepage) {
            modal.find('.gpb-modal-homepage-link').attr('href', data.homepage).show();
        } else {
            modal.find('.gpb-modal-homepage-link').hide();
        }
        modal.find('.gpb-modal-readme').html(data.readme);

        // Show the modal
        modal.fadeIn();
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
                // You can add a loading indicator here if desired.
            },
            success: function(response) {
                if (response.success) {
                    openGpbModal(response.data);
                } else {
                    alert(response.data.message);
                }
            },
            error: function() {
                alert(gpb_ajax_object.error_message || 'An error occurred.');
            }
        });
    });

    // Click event to close the modal.
    $('.gpb-modal-close').on('click', function() {
        closeGpbModal();
    });

    // Click outside the modal content to close.
    $(window).on('click', function(event) {
        var modal = $('#gpb-plugin-modal');
        if (event.target === modal[0]) {
            closeGpbModal();
        }
    });
});
