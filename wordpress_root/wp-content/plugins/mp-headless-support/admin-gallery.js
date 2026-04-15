jQuery(document).ready(function($) {
    var frame;
    var container = $('#mp_gallery_images_container');
    var input = $('#mp_gallery_images_ids');

    // Initialize Sortable
    if (container.length) {
        container.sortable({
            update: updateIds
        });
    }

    // Handle "Add Images" click
    $('#mp_add_gallery_images').on('click', function(e) {
        e.preventDefault();

        // If the media frame already exists, reopen it.
        if (frame) {
            frame.open();
            return;
        }

        // Create a new media frame
        frame = wp.media({
            title: 'Select Images for Gallery',
            button: {
                text: 'Add to Gallery'
            },
            multiple: true
        });

        // When an image is selected in the media frame...
        frame.on('select', function() {
            var selection = frame.state().get('selection');
            
            selection.map(function(attachment) {
                attachment = attachment.toJSON();
                // Avoid duplicates? Or allow them? Let's allow for now or check unique.
                // Checking unique logic if desired:
                // if (input.val().split(',').includes(attachment.id.toString())) return;

                container.append(
                    '<div class="mp-gallery-image" data-id="' + attachment.id + '">' +
                        '<img src="' + (attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url) + '" />' +
                        '<div class="mp-gallery-remove">&times;</div>' +
                    '</div>'
                );
            });

            updateIds();
        });

        frame.open();
    });

    // Handle "Remove" click
    container.on('click', '.mp-gallery-remove', function() {
        $(this).parent().remove();
        updateIds();
    });

    // Update the hidden input with the list of IDs
    function updateIds() {
        var ids = [];
        container.find('.mp-gallery-image').each(function() {
            ids.push($(this).data('id'));
        });
        input.val(ids.join(','));
    }
});
